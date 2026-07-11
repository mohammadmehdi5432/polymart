import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import LanguageSelect from './components/ui/LanguageSelect';
import { useTargetLanguages } from './hooks/useTargetLanguages';
import { fetchJob, jobAction, refreshJobStats, abortJobStep, testTranslationApi } from './api/job';
import { HiBolt, HiArrowPath } from './components/ui/icons';

const POLL_INTERVAL_MS = 2000;
/** If cron has not ticked for this long, nudge/ensure the server worker. */
const CRON_STALE_SEC = 20;
/** Lock alone is only trusted this long without a completed tick. */
const LOCK_HEALTHY_SEC = 90;
/** Between-item lock with no progress — force ensure. */
const LOCK_IDLE_STALE_SEC = 45;
const AUTO_RUN_STORAGE_KEY = 'polymart_ai_autotranslate_autorun';
const POLL_ERROR_NOTICE_THRESHOLD = 3;

function stepLogSignature(step) {
  if (!step) {
    return '';
  }

  return `${step.post_id ?? ''}:${step.status ?? ''}:${step.time ?? ''}:${step.message ?? ''}`;
}

function isCronHealthy(job) {
  const now = Math.floor(Date.now() / 1000);
  const lastCron = Number(job?.last_cron_at || 0);
  const heartbeat = Number(job?.worker_heartbeat_at || 0);
  const lastActivity = Math.max(lastCron, heartbeat);
  const activityAge = lastActivity > 0 ? now - lastActivity : Number.POSITIVE_INFINITY;
  const lockAge = Number(job?.worker_lock_age || 0);
  const hasActivePost = Boolean(job?.current_post?.title && !job?.current_post?.from_last_step);

  if (job?.worker_lock) {
    // Actively translating a known post — trust the lock for one AI-call window.
    if (hasActivePost && lockAge >= 0 && lockAge < LOCK_HEALTHY_SEC) {
      return true;
    }

    // Between items with a stuck lock — NOT healthy (this is the production stall).
    if (!hasActivePost && activityAge >= LOCK_IDLE_STALE_SEC) {
      return false;
    }

    return lockAge >= 0 && lockAge < LOCK_HEALTHY_SEC && activityAge < LOCK_HEALTHY_SEC;
  }

  if (job?.worker_alive && activityAge < CRON_STALE_SEC) {
    return true;
  }

  const stamp = Number(job?.last_cron_at || job?.worker_heartbeat_at || job?.worker_scheduled_at || 0);

  if (!stamp) {
    return false;
  }

  const age = now - stamp;
  return age >= 0 && age < CRON_STALE_SEC;
}

function resolveDisplayPost(job, stickyPost = null) {
  if (job?.current_post?.title) {
    return job.current_post;
  }

  if (job?.last_step?.title || job?.last_step?.post_id) {
    return {
      post_id: job.last_step.post_id,
      title: job.last_step.title || `#${job.last_step.post_id}`,
      step_status: job.last_step.status,
      step_message: job.last_step.message,
      partial_phase: job.partial_phase,
      partial_progress: job.partial_progress,
      from_last_step: true,
    };
  }

  if (stickyPost?.title) {
    return stickyPost;
  }

  return null;
}

function mergeJobSnapshot(prev, data) {
  if (!data || typeof data !== 'object') {
    return prev;
  }

  const merged = { ...data };

  if (!merged.current_post?.title) {
    const fromLast = resolveDisplayPost(merged, prev?.current_post);
    if (fromLast?.title) {
      merged.current_post = fromLast;
    } else if (prev?.current_post?.title) {
      merged.current_post = prev.current_post;
    }
  }

  return merged;
}

function writeAutoRunFlag(active) {
  if (typeof window === 'undefined') {
    return;
  }

  if (active) {
    window.localStorage.setItem(AUTO_RUN_STORAGE_KEY, '1');
  } else {
    window.localStorage.removeItem(AUTO_RUN_STORAGE_KEY);
  }
}

function jobPhaseLabel(phase) {
  switch (phase) {
    case 'elementor':
      return 'Elementor';
    case 'variations':
      return 'تنوع‌های محصول';
    case 'commerce':
      return 'ویژگی‌ها و دسته‌ها';
    case 'core':
      return 'محتوای اصلی';
    case 'fields':
      return 'فیلدهای متنی';
    default:
      return phase || '';
  }
}

function jobStepHeadline(lastStepStatus, displayPost, job) {
  if (job?.worker_lock && Number(job?.worker_lock_age || 0) > 120) {
    return 'قفل مرحله گیر کرده — در حال بازیابی…';
  }

  const hasActivePost = Boolean(displayPost?.title && !displayPost?.from_last_step);

  if (job?.worker_lock && hasActivePost) {
    return 'کارگر سرور در حال ترجمه است';
  }

  if (job?.worker_lock && displayPost?.from_last_step && job?.status === 'running') {
    return 'قفل بین دو مورد گیر کرده — در حال بازیابی کارگر…';
  }

  if (displayPost?.from_last_step) {
    if (job?.status === 'running') {
      return job?.cron_scheduled
        ? 'آخرین مورد تمام شد — منتظر تیک بعدی کرون'
        : 'آخرین مورد تمام شد — در حال زمان‌بندی تیک بعدی';
    }
    return 'آخرین مورد این اجرا';
  }

  if (!displayPost?.title) {
    return 'در حال آماده‌سازی مورد بعدی';
  }

  const phase = job?.partial_phase || displayPost?.partial_phase;
  const progress = job?.partial_progress || displayPost?.partial_progress;

  if (lastStepStatus === 'deferred') {
    return 'موقتاً کنار گذاشته شد — ادامه بعداً';
  }

  if (lastStepStatus === 'partial' && phase) {
    const label = jobPhaseLabel(phase);
    return progress ? `ادامه ترجمه (${label} — ${progress})` : `ادامه ترجمه (${label})`;
  }

  if (lastStepStatus === 'partial') {
    const gapHint = job?.last_step?.message || displayPost?.step_message || '';
    if (gapHint) {
      return 'ادامه فیلدهای باقی‌مانده';
    }
    return 'تلاش مجدد برای مورد ناقص';
  }

  return 'در حال ترجمه';
}

const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};
const aiConfigured = Boolean(config.aiConfigured);

function formatTime(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('fa-IR');
}

function formatStepLog(step) {
  if (!step) {
    return null;
  }

  const prefix = step.title ? `#${step.post_id} «${step.title}»` : `#${step.post_id}`;
  return step.message || `${prefix} — ${step.status}`;
}

export default function AutoTranslateApp() {
  const {
    langOptions,
    targetLang,
    setTargetLang,
    loading: langsLoading,
    error: langsError,
    targetLabel,
  } = useTargetLanguages('en');

  const [job, setJob] = useState(null);
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionPending, setActionPending] = useState(null);
  const [refreshingStats, setRefreshingStats] = useState(false);
  const [notice, setNotice] = useState(null);
  const [activePost, setActivePost] = useState(null);
  const [apiTestText, setApiTestText] = useState('سلام دنیا');
  const [apiTestLoading, setApiTestLoading] = useState(false);
  const [apiTestResult, setApiTestResult] = useState(null);
  const autoResumedRef = useRef(false);
  const lastStepLogRef = useRef('');
  const pollErrorCountRef = useRef(0);
  const logsRef = useRef(null);
  const isActiveRef = useRef(true);
  const activePostRef = useRef(null);

  useEffect(() => {
    isActiveRef.current = true;

    return () => {
      isActiveRef.current = false;
    };
  }, []);

  const appendLog = useCallback((message, type = 'info') => {
    if (!message) {
      return;
    }

    setLogs((prev) => [...prev, { id: `${Date.now()}-${Math.random()}`, message, type }]);
  }, []);

  const syncServerLogs = useCallback((recentLogs) => {
    if (!Array.isArray(recentLogs) || recentLogs.length === 0) {
      return;
    }

    // Server sends newest-first; show oldest→newest. Replace panel with this run's server log.
    const chronological = [...recentLogs].reverse();
    const seenMessages = new Set();
    const next = [];

    chronological.forEach((entry) => {
      const raw = String(entry.message || '').trim();
      if (!raw) {
        return;
      }

      // Drop near-duplicate lines (same text within the run).
      const dedupeKey = raw.replace(/\s+/g, ' ');
      if (seenMessages.has(dedupeKey)) {
        return;
      }
      seenMessages.add(dedupeKey);

      next.push({
        id: entry.id || `srv-${entry.time}-${dedupeKey}`,
        message: entry.time ? `[${formatTime(entry.time)}] ${raw}` : raw,
        type:
          entry.level === 'success'
            ? 'success'
            : entry.level === 'error'
              ? 'error'
              : entry.level === 'warning'
                ? 'warning'
                : 'info',
      });
    });

    setLogs(next.slice(-200));
  }, []);

  const appendStepLog = useCallback(
    (step) => {
      const signature = stepLogSignature(step);

      if (!signature || signature === lastStepLogRef.current) {
        return;
      }

      lastStepLogRef.current = signature;

      const message = formatStepLog(step);

      if (!message) {
        return;
      }

      // Prefer server recent_logs for "translated" lines — avoid duplicate client echoes.
      if (step.status === 'translated' || step.status === 'skipped') {
        return;
      }

      const type =
        step.status === 'failed'
          ? 'error'
          : step.status === 'skipped'
            ? 'warning'
          : step.status === 'partial'
            ? 'warning'
            : 'info';

      appendLog(message, type);
    },
    [appendLog]
  );

  const loadJob = useCallback(async () => {
    try {
      const data = await fetchJob();
      pollErrorCountRef.current = 0;
      const merged = mergeJobSnapshot(null, data);
      setJob(merged);

      const display = resolveDisplayPost(merged, activePostRef.current);
      if (display?.title) {
        activePostRef.current = display;
        setActivePost(display);
      }

      if (Array.isArray(data?.recent_logs)) {
        syncServerLogs(data.recent_logs);
      }

      if (data?.lang && data.status !== 'idle') {
        setTargetLang(data.lang);
      }

      return merged;
    } catch (error) {
      pollErrorCountRef.current += 1;
      const status = error?.response?.status;

      if (status === 403) {
        setNotice({
          type: 'warning',
          message: 'نشست ادمین منقضی شده — صفحه را رفرش کنید یا دوباره وارد شوید.',
        });
      } else {
        setNotice({
          type: 'warning',
          message:
            'بارگذاری وضعیت موقتاً ناموفق بود — اگر ترجمه در حال اجراست چند ثانیه صبر کنید؛ کارگر روی سرور ادامه می‌دهد.',
        });
      }

      return null;
    }
  }, [setTargetLang, syncServerLogs]);

  useEffect(() => {
    loadJob().finally(() => setLoading(false));
  }, [loadJob]);

  useEffect(() => {
    if (logsRef.current) {
      logsRef.current.scrollTop = logsRef.current.scrollHeight;
    }
  }, [logs]);

  const applyJobUpdate = useCallback((data) => {
    if (!data) {
      return null;
    }

    let merged = null;
    setJob((prev) => {
      merged = mergeJobSnapshot(prev, data);
      return merged;
    });

    const display = resolveDisplayPost(data, activePostRef.current);
    if (display?.title) {
      activePostRef.current = display;
      setActivePost(display);
    }

    if (Array.isArray(data.recent_logs) && data.recent_logs.length > 0) {
      syncServerLogs(data.recent_logs);
    } else if (data.last_step) {
      appendStepLog(data.last_step);
    }

    return merged;
  }, [appendStepLog, syncServerLogs]);

  /**
   * Light server nudge: schedule/ping cron only — never run translation in the browser.
   */
  const ensureServerWorker = useCallback(async () => {
    try {
      const data = await jobAction('ensure');
      if (isActiveRef.current) {
        applyJobUpdate(data);
      }
      return data;
    } catch {
      return null;
    }
  }, [applyJobUpdate]);

  useEffect(() => {
    if (!job || job.status !== 'running') {
      return undefined;
    }

    writeAutoRunFlag(true);

    let ensureInFlight = false;

    const tick = async () => {
      if (!isActiveRef.current) {
        return;
      }

      try {
        const data = await fetchJob();
        if (!data || !isActiveRef.current) {
          return;
        }

        pollErrorCountRef.current = 0;
        applyJobUpdate(data);

        if (data.status === 'completed') {
          appendLog('ترجمه خودکار با موفقیت به پایان رسید.', 'success');
          writeAutoRunFlag(false);
          return;
        }

        if (data.status === 'paused') {
          if (data.pause_reason === 'critical') {
            appendLog(data.last_error || 'به‌خاطر خطای API متوقف شد.', 'error');
            setNotice({
              type: 'error',
              message: data.last_error || 'ترجمه به‌خاطر خطای API متوقف شد.',
            });
          } else if (data.pause_reason === 'stalled' || data.pause_reason === 'pick_stalled') {
            appendLog(data.last_error || 'صف ترجمه گیر کرد.', 'warning');
            setNotice({
              type: 'warning',
              message: data.last_error || 'صف ترجمه گیر کرد — «ادامه» یا رد کردن مورد فعلی را بزنید.',
            });
          }
          writeAutoRunFlag(false);
          return;
        }

        if (
          data.status === 'running' &&
          !isCronHealthy(data) &&
          !ensureInFlight
        ) {
          const lockAge = Number(data.worker_lock_age || 0);
          const hasActivePost = Boolean(
            data.current_post?.title && !data.current_post?.from_last_step
          );
          // Only block ensure while a real in-flight post translation owns the lock.
          const lockBlocks =
            Boolean(data.worker_lock) &&
            hasActivePost &&
            lockAge >= 0 &&
            lockAge < LOCK_HEALTHY_SEC;

          if (!lockBlocks) {
            ensureInFlight = true;
            await ensureServerWorker();
            ensureInFlight = false;
          }
        }
      } catch (error) {
        pollErrorCountRef.current += 1;

        if (pollErrorCountRef.current >= POLL_ERROR_NOTICE_THRESHOLD && isActiveRef.current) {
          const status = error?.response?.status;
          setNotice({
            type: 'warning',
            message:
              status === 403
                ? 'نشست ادمین منقضی شده — صفحه را رفرش کنید.'
                : 'به‌روزرسانی مانیتور ناموفق بود — کارگر کرون روی سرور مستقل از این تب ادامه می‌دهد.',
          });
        }
      }
    };

    tick();
    const timer = window.setInterval(tick, POLL_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [job?.status, applyJobUpdate, ensureServerWorker, appendLog]);

  useEffect(() => {
    if (loading || autoResumedRef.current) {
      return;
    }

    if (job?.status !== 'running') {
      return;
    }

    autoResumedRef.current = true;
    appendLog('اجرای ناتمام پیدا شد — مانیتور به کارگر کرون سرور وصل شد.', 'info');
    ensureServerWorker();
  }, [loading, job?.status, ensureServerWorker, appendLog]);

  const handleStart = async () => {
    if (!aiConfigured) {
      setNotice({
        type: 'error',
        message: 'کلید API یا آدرس AI Gateway در تنظیمات ترجمه تنظیم نشده است.',
      });
      return;
    }

    setNotice(null);
    setLogs([]);
    setActivePost(null);
    lastStepLogRef.current = '';
    autoResumedRef.current = true;
    writeAutoRunFlag(true);
    setActionPending('start');
    setJob((prev) => ({
      ...(prev ?? {}),
      status: 'running',
      lang: targetLang,
      lang_label: targetLabel,
      last_error: null,
      pause_reason: null,
      current_post: null,
      current_post_id: null,
      step_started_at: null,
    }));
    appendLog(`در حال آماده‌سازی صف ترجمه برای ${targetLabel}…`);

    try {
      const data = await jobAction('start', targetLang);
      setJob(data);
      setActionPending(null);
      appendLog(
        `ترجمه خودکار شروع شد — ${data.total ?? 0} مورد در صف (${targetLabel}). کارگر کرون روی سرور اجرا می‌شود.`,
        'success'
      );
      await ensureServerWorker();
    } catch (error) {
      const code = error?.response?.data?.code;
      const message = error?.response?.data?.message || 'شروع ترجمه خودکار ناموفق بود.';

      if (code === 'polymart_ai_job_running') {
        const existing = await loadJob();

        if (existing?.status === 'running') {
          setNotice({
            type: 'info',
            message: 'یک ترجمه از قبل در حال اجراست — مانیتور همین اجرا را نشان می‌دهد.',
          });
          await ensureServerWorker();
        } else {
          setNotice({ type: 'error', message });
        }
      } else {
        setNotice({ type: 'error', message });
      }

      appendLog(message, 'error');
      autoResumedRef.current = false;
      writeAutoRunFlag(false);
      await loadJob();
    } finally {
      setActionPending(null);
    }
  };

  const handleResume = async () => {
    setNotice(null);
    setActionPending('resume');
    writeAutoRunFlag(true);
    setJob((prev) => ({
      ...(prev ?? {}),
      status: 'running',
      last_error: null,
      pause_reason: null,
      current_post: null,
      current_post_id: null,
      step_started_at: null,
    }));
    appendLog('در حال از سرگیری ترجمه روی کارگر کرون…');

    try {
      const data = await jobAction('resume');
      setJob(data);
      setActionPending(null);
      appendLog('ترجمه از سر گرفته شد — کارگر کرون ادامه می‌دهد.', 'success');
      await ensureServerWorker();
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'ادامه ناموفق بود.' });
      await loadJob();
    } finally {
      setActionPending(null);
    }
  };

  const handleSkip = async () => {
    const postId =
      Number(job?.partial_post_id) ||
      Number(activePost?.post_id) ||
      Number(job?.current_post?.post_id) ||
      Number(job?.last_step?.post_id) ||
      0;

    if (!postId || Boolean(actionPending)) {
      return;
    }

    setNotice(null);
    setActionPending('skip');
    abortJobStep();

    try {
      const jobLang = job?.lang || targetLang;
      const data = await jobAction('skip', jobLang, { post_id: postId });
      setJob(data);

      if (data?.last_step) {
        appendStepLog(data.last_step);
      } else {
        appendLog(`#${postId} رد شد — کارگر کرون مورد بعدی را می‌گیرد.`, 'warning');
      }

      setActivePost(null);

      if (data?.status === 'running') {
        await ensureServerWorker();
      }
    } catch (error) {
      setNotice({
        type: 'error',
        message: error?.response?.data?.message || 'رد کردن مورد ناموفق بود.',
      });
      await loadJob();
    } finally {
      setActionPending(null);
    }
  };

  const handlePause = async () => {
    writeAutoRunFlag(false);
    abortJobStep();
    setActionPending('pause');
    setJob((prev) => ({
      ...(prev ?? {}),
      status: 'paused',
      current_post: null,
      current_post_id: null,
      step_started_at: null,
    }));
    appendLog('در حال توقف موقت…');

    try {
      const data = await jobAction('pause');
      setJob(data);
      appendLog('ترجمه متوقف موقت شد.');
    } catch {
      setNotice({ type: 'error', message: 'توقف موقت ناموفق بود.' });
      await loadJob();
    } finally {
      setActionPending(null);
    }
  };

  const handleStop = async () => {
    writeAutoRunFlag(false);
    abortJobStep();
    setActivePost(null);
    setActionPending('stop');
    setJob((prev) => ({
      ...(prev ?? {}),
      status: 'idle',
      current_post: null,
      current_post_id: null,
      partial_post_id: null,
      step_started_at: null,
      last_error: null,
      pause_reason: null,
    }));
    appendLog('در حال توقف کامل…');

    try {
      const data = await jobAction('stop');
      setJob(data);
      autoResumedRef.current = false;
      appendLog('ترجمه متوقف شد.');
    } catch {
      setNotice({ type: 'error', message: 'توقف ناموفق بود — صفحه را رفرش کنید.' });
      await loadJob();
    } finally {
      setActionPending(null);
    }
  };

  const handleRefreshStats = async () => {
    setRefreshingStats(true);
    setNotice(null);

    try {
      const jobLang = job?.lang || targetLang;
      const data = await refreshJobStats(jobLang);
      setJob(data);
      const stats = data?.live_stats ?? {};
      appendLog(
        `آمار به‌روز شد — ${stats.translated ?? 0} ترجمه‌شده، ${stats.partial ?? 0} ناقص، ${stats.untranslated ?? 0} ترجمه‌نشده.`
      );
    } catch (error) {
      setNotice({
        type: 'error',
        message: error?.response?.data?.message || 'به‌روزرسانی آمار ناموفق بود.',
      });
    } finally {
      setRefreshingStats(false);
    }
  };

  const handleApiTest = async () => {
    setApiTestLoading(true);
    setApiTestResult(null);
    setNotice(null);

    try {
      const data = await testTranslationApi({ text: apiTestText.trim() || 'سلام دنیا', lang: targetLang });
      setApiTestResult(data);

      if (data?.success) {
        appendLog(
          `تست آروان موفق (${data.elapsed_ms}ms): «${data.source}» → «${data.translated}»`,
          'success'
        );
      } else {
        appendLog(`تست آروان ناموفق (${data?.elapsed_ms ?? '?'}ms): ${data?.error || 'خطای نامشخص'}`, 'error');
      }
    } catch (error) {
      const message = error?.response?.data?.message || error?.message || 'تست API ناموفق بود.';
      setApiTestResult({ success: false, error: message });
      appendLog(`تست آروان ناموفق: ${message}`, 'error');
    } finally {
      setApiTestLoading(false);
    }
  };

  const queueTotal = job?.total ?? job?.initial_total ?? 0;
  const runDone = job?.run_done ?? job?.completed ?? job?.succeeded ?? 0;
  const runRemaining = job?.run_remaining ?? Math.max(0, queueTotal - runDone);
  const runPending = job?.run_pending ?? 0;
  const progress = job?.progress_pct ?? (queueTotal > 0 ? Math.round((runDone / queueTotal) * 100) : 0);
  const steps = job?.steps ?? job?.processed ?? 0;
  const liveStats = job?.live_stats ?? {};
  const needsWork =
    job?.needs_work ??
    (liveStats.untranslated != null
      ? (liveStats.untranslated ?? 0) + (liveStats.partial ?? 0)
      : job?.remaining ?? 0);
  const initialNeedsWork = job?.initial_needs_work ?? 0;
  const siteResolved = job?.site_resolved ?? (initialNeedsWork > 0 ? Math.max(0, initialNeedsWork - needsWork) : 0);
  const siteProgress =
    job?.site_progress_pct ??
    (initialNeedsWork > 0 ? Math.min(100, Math.round((siteResolved / initialNeedsWork) * 100)) : 0);
  const queueBacklog = job?.queue_backlog ?? job?.deferred_pending ?? runPending;
  const isRunning = job?.status === 'running';
  const isPaused = job?.status === 'paused';
  const isOutdated = Boolean(job?.outdated);
  const isBusy = Boolean(actionPending) || refreshingStats;
  const isSkipDisabled = refreshingStats || Boolean(actionPending);
  const canResume = isPaused && needsWork > 0 && !isBusy;
  const canStart =
    aiConfigured && !loading && !isBusy && !isRunning && !langsLoading && langOptions.length > 0;
  const activeLangLabel = job?.lang_label || targetLabel;
  const displayPost = resolveDisplayPost(job, activePost);
  const lastStepStatus = displayPost?.step_status || job?.last_step?.status;
  const skipTargetId =
    Number(job?.partial_post_id) ||
    Number(displayPost?.post_id) ||
    Number(job?.current_post?.post_id) ||
    Number(job?.last_step?.post_id) ||
    0;
  const canSkip =
    skipTargetId > 0 &&
    !loading &&
    (isRunning || isPaused) &&
    actionPending !== 'stop';

  const actionPendingLabel =
    actionPending === 'start'
      ? 'در حال شروع…'
      : actionPending === 'resume'
        ? 'در حال ادامه…'
        : actionPending === 'pause'
          ? 'در حال توقف موقت…'
          : actionPending === 'stop'
            ? 'در حال توقف کامل…'
            : actionPending === 'skip'
              ? 'در حال رد کردن…'
              : null;

  const cronAgeSec = (() => {
    const stamp = Number(job?.last_cron_at || job?.worker_heartbeat_at || 0);
    if (!stamp) {
      return null;
    }
    return Math.max(0, Math.floor(Date.now() / 1000) - stamp);
  })();

  const workerLabel =
    job?.last_worker === 'cron' ? 'کرون' : job?.last_worker === 'admin' ? 'سرور' : null;

  const statusLabel = actionPendingLabel
    ? actionPendingLabel
    : isRunning
      ? job?.worker_lock
        ? `کرون در حال ترجمه${workerLabel ? ` (${workerLabel})` : ''}`
        : cronAgeSec != null
          ? cronAgeSec < CRON_STALE_SEC
            ? `در حال اجرا روی سرور — آخرین تیک ${cronAgeSec}ث پیش`
            : `در حال اجرا — آخرین فعالیت ${cronAgeSec}ث پیش`
          : job?.cron_scheduled
            ? 'زمان‌بندی شده — منتظر اجرای crontab'
            : 'در حال اجرا روی سرور (کارگر کرون)'
      : isPaused
        ? isOutdated
          ? 'نیاز به اجرای مجدد'
          : 'متوقف'
        : job?.status === 'completed'
          ? 'تمام شد'
          : 'آماده';

  return (
    <Layout
      title="ترجمه خودکار"
      subtitle={`ترجمه خودکار محتوای فارسی به ${targetLabel} — اجرا روی سرور با کرون، مانیتور در این صفحه`}
    >
      <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
        <p className="font-medium">کارگر کرون سرور (بدون وابستگی به مرورگر)</p>
        <p className="mt-1 text-pmai-muted">
          علاوه بر زنجیرهٔ سریع، یک pulse تکراری هر دقیقه روی سرور می‌ماند تا حتی با بستن این تب صف جلو برود.
          فاصلهٔ چند دقیقه‌ای بین محصولات معمولاً زمان خود ترجمهٔ AI است، نه توقف کرون.
        </p>
        <p className="mt-1">
          ترجمه فقط روی سرور با WP-Cron اجرا می‌شود. بستن یا قطع شدن تب هیچ اثری روی صف ندارد — این صفحه فقط مانیتور و کنترل
          (شروع / توقف / رد) است. آمار «کل سایت» منبع حقیقت پیشرفت است.
        </p>
      </div>

      {notice && (
        <div className="mb-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      {langsError && (
        <div className="mb-4">
          <Notice type="warning" message={langsError} />
        </div>
      )}

      {!loading && !aiConfigured && (
        <div className="mb-4">
          <Notice
            type="error"
            message="کلید API یا آدرس AI Gateway در تنظیمات ترجمه تنظیم نشده — ابتدا از بخش تنظیمات پیکربندی کنید."
          />
        </div>
      )}

      {config.cronDisabled ? (
        <div className="mb-4">
          <Notice
            type="info"
            message="حالت سرور فعال است (DISABLE_WP_CRON): با زدن شروع، اولین تیک همان لحظه اجرا می‌شود؛ ادامه‌اش با crontab هر ۱ دقیقه (مسیر واقعی wp-cron.php سایت، نه /path/to/)."
          />
        </div>
      ) : !config.devMode ? (
        <div className="mb-4">
          <Notice
            type="warning"
            message="پیشنهاد پروداکشن: در wp-config مقدار define('DISABLE_WP_CRON', true) بگذارید و در cPanel هر ۱–۲ دقیقه /usr/local/bin/php …/wp-cron.php را زمان‌بندی کنید تا ترجمه مستقل از ترافیک سایت پیش برود."
          />
        </div>
      ) : null}

      {!loading && job?.status === 'running' && (job?.last_cron_at || job?.worker_heartbeat_at) ? (
        <div className="mb-4">
          <Notice
            type="info"
            message={`کارگر سرور زنده است — آخرین فعالیت: ${formatTime(
              job.last_cron_at || job.worker_heartbeat_at
            )}${job.last_cron_steps ? ` (${job.last_cron_steps} مرحله)` : ''}${
              job.next_cron_at ? ` · تیک بعدی حدود ${formatTime(job.next_cron_at)}` : ''
            }`}
          />
        </div>
      ) : !loading && job?.status === 'running' && job?.cron_scheduled ? (
        <div className="mb-4">
          <Notice
            type="info"
            message={`کارگر زمان‌بندی شده است${
              job.next_cron_at ? ` — اجرای بعدی حدود ${formatTime(job.next_cron_at)}` : ''
            }. با DISABLE_WP_CRON تا رسیدن crontab بعدی صبر کنید؛ شروع/ادامه خودش اولین تیک را می‌زند.`}
          />
        </div>
      ) : !loading && job?.status === 'running' ? (
        <div className="mb-4">
          <Notice
            type="warning"
            message="هنوز event کرون دیده نمی‌شود — یک‌بار «ادامه» بزنید. مسیر crontab باید به wp-cron.php واقعی همین سایت اشاره کند."
          />
        </div>
      ) : null}

      {!loading && isOutdated && (
        <div className="mb-4">
          <Notice
            type="warning"
            message={
              job?.last_error ||
              `${needsWork} مورد هنوز نیاز به ترجمه دارد. اجرای قبلی کامل نبود — دوباره «شروع ترجمه خودکار» را بزنید.`
            }
          />
        </div>
      )}

      <div className="mb-6 grid gap-4 lg:grid-cols-3">
        <div className="rounded-lg border border-pmai-border bg-white p-5 lg:col-span-1">
          <label htmlFor="auto-target-lang" className="mb-1 block text-xs font-medium text-gray-700">
            زبان مقصد
          </label>
          <LanguageSelect
            id="auto-target-lang"
            value={targetLang}
            onChange={setTargetLang}
            options={langOptions}
            loading={langsLoading}
            disabled={isRunning || Boolean(actionPending)}
            className="mb-4 w-full"
          />

          <div className="space-y-2">
            <button
              type="button"
              onClick={handleStart}
              disabled={!canStart || langOptions.length === 0}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-pmai-primary px-4 py-2.5 text-sm font-medium text-white hover:bg-pmai-primary-dark disabled:cursor-not-allowed disabled:opacity-50"
            >
              <HiBolt className={`h-4 w-4 ${actionPending === 'start' ? 'animate-pulse' : ''}`} />
              {actionPending === 'start'
                ? 'در حال شروع…'
                : needsWork > 0
                  ? `شروع ترجمه (${needsWork} مورد)`
                  : 'شروع ترجمه خودکار'}
            </button>
            {canResume && (
              <button
                type="button"
                onClick={handleResume}
                disabled={isBusy}
                className="w-full cursor-pointer rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-sm font-medium text-green-800 hover:bg-green-100 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {actionPending === 'resume' ? 'در حال ادامه…' : `ادامه (${needsWork} باقی‌مانده)`}
              </button>
            )}
            {(isRunning || actionPending === 'pause') && (
              <button
                type="button"
                onClick={handlePause}
                disabled={Boolean(actionPending) && actionPending !== 'pause'}
                className="w-full cursor-pointer rounded-lg border border-pmai-border px-4 py-2 text-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {actionPending === 'pause' ? 'در حال توقف موقت…' : 'توقف موقت'}
              </button>
            )}
            {canSkip && (
              <button
                type="button"
                onClick={handleSkip}
                disabled={isSkipDisabled}
                className="w-full cursor-pointer rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {actionPending === 'skip'
                  ? 'در حال رد کردن…'
                  : `رد کردن #${skipTargetId} → بعدی`}
              </button>
            )}
            {(isRunning || isPaused || actionPending === 'stop') && (
              <button
                type="button"
                onClick={handleStop}
                disabled={actionPending === 'stop'}
                className="w-full cursor-pointer rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {actionPending === 'stop' ? 'در حال توقف کامل…' : 'توقف کامل'}
              </button>
            )}
            <button
              type="button"
              onClick={handleRefreshStats}
              disabled={refreshingStats || langsLoading || langOptions.length === 0}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg border border-pmai-border px-4 py-2 text-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <HiArrowPath className={`h-4 w-4 ${refreshingStats ? 'animate-spin' : ''}`} />
              {refreshingStats ? 'در حال به‌روزرسانی آمار…' : 'به‌روزرسانی آمار کل سایت'}
            </button>
          </div>

          <div className="mt-5 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4">
            <p className="text-xs font-medium text-gray-800">تست سریع آروان‌کلاد</p>
            <p className="mt-1 text-xs text-gray-600">
              همان مسیر ترجمه واقعی — اگر اینجا timeout بخورد، مشکل از API است نه از محصول.
            </p>
            <input
              type="text"
              value={apiTestText}
              onChange={(event) => setApiTestText(event.target.value)}
              disabled={apiTestLoading}
              className="mt-3 w-full rounded border border-pmai-border bg-white px-3 py-2 text-sm disabled:opacity-50"
              placeholder="متن تستی فارسی"
            />
            <button
              type="button"
              onClick={handleApiTest}
              disabled={apiTestLoading}
              className="mt-2 w-full cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-900 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {apiTestLoading ? 'در حال ارسال به آروان… (تا ۲ دقیقه)' : `تست ترجمه به ${targetLabel}`}
            </button>
            {apiTestResult && (
              <div
                className={`mt-3 rounded border px-3 py-2 text-xs ${
                  apiTestResult.success
                    ? 'border-green-200 bg-green-50 text-green-900'
                    : 'border-red-200 bg-red-50 text-red-800'
                }`}
              >
                {apiTestResult.success ? (
                  <>
                    <p className="font-medium">موفق — {apiTestResult.elapsed_ms}ms</p>
                    <p className="mt-1">«{apiTestResult.source}» → «{apiTestResult.translated}»</p>
                    {apiTestResult.model ? <p className="mt-1 opacity-80">مدل: {apiTestResult.model}</p> : null}
                  </>
                ) : (
                  <>
                    <p className="font-medium">
                      ناموفق
                      {apiTestResult.elapsed_ms != null ? ` — ${apiTestResult.elapsed_ms}ms` : ''}
                    </p>
                    <p className="mt-1 break-words">{apiTestResult.error}</p>
                    {apiTestResult.error_code ? (
                      <p className="mt-1 opacity-80">[{apiTestResult.error_code}]</p>
                    ) : null}
                  </>
                )}
              </div>
            )}
          </div>
        </div>

        <div className="rounded-lg border border-pmai-border bg-white p-5 lg:col-span-2">
          <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-base font-semibold text-gray-900">وضعیت فعلی</h3>
              <p className="mt-1 text-xs text-pmai-muted">
                زبان این اجرا: <strong>{activeLangLabel}</strong>
                {job?.lang ? ` (${job.lang})` : ''}
              </p>
            </div>
            {(isRunning || actionPending) && (
              <span className="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800">
                <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500" />
                {actionPendingLabel || 'مانیتور کرون'}
              </span>
            )}
          </div>

          {loading ? (
            <p className="text-pmai-muted">در حال بارگذاری…</p>
          ) : (
            <>
              {(isRunning && (displayPost?.title || job?.worker_lock || job?.last_step)) && (
                <div
                  className={`mb-4 rounded-lg border px-4 py-3 text-sm ${
                    lastStepStatus === 'partial'
                      ? 'border-amber-200 bg-amber-50 text-amber-950'
                      : 'border-blue-200 bg-blue-50 text-blue-900'
                  }`}
                >
                  <p className={`text-xs ${lastStepStatus === 'partial' ? 'text-amber-700' : lastStepStatus === 'deferred' ? 'text-violet-700' : 'text-blue-700'}`}>
                    {jobStepHeadline(lastStepStatus, displayPost, job)}
                  </p>
                  {displayPost?.title ? (
                    <>
                      <p className="font-medium">
                        #{displayPost.post_id} — {displayPost.title}
                      </p>
                      {displayPost.step_message ? (
                        <p className="mt-1 text-xs opacity-90">{displayPost.step_message}</p>
                      ) : null}
                    </>
                  ) : (
                    <p className="mt-1 text-xs opacity-80">
                      {job?.worker_lock
                        ? 'قفل مرحله روی سرور فعال است — منتظر اتمام slice…'
                        : 'بین دو مرحله / انتخاب مورد بعدی…'}
                    </p>
                  )}
                  {job?.last_cron_at ? (
                    <p className="mt-2 text-xs opacity-80">
                      آخرین تیک کارگر: {formatTime(job.last_cron_at)}
                      {job.last_cron_steps ? ` · ${job.last_cron_steps} مرحله` : ''}
                      {job.worker_lock ? ' · قفل فعال' : ''}
                      {job.cron_scheduled ? ' · event بعدی زمان‌بندی شده' : ''}
                    </p>
                  ) : null}
                  {canSkip && (
                    <button
                      type="button"
                      onClick={handleSkip}
                      disabled={isSkipDisabled}
                      className="mt-3 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-50 disabled:opacity-50"
                    >
                      {actionPending === 'skip' ? 'در حال رد کردن…' : `رد کردن #${skipTargetId} و رفتن به بعدی`}
                    </button>
                  )}
                </div>
              )}

              <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[
                  { label: 'وضعیت', value: statusLabel },
                  {
                    label: 'پیشرفت کل سایت',
                    value:
                      initialNeedsWork > 0
                        ? `${siteResolved} / ${initialNeedsWork}`
                        : liveStats.translated != null
                          ? `${liveStats.translated} ترجمه‌شده`
                          : '—',
                    hint:
                      needsWork > 0
                        ? `${needsWork} باقی‌مانده · ${liveStats.partial ?? 0} ناقص · ${liveStats.untranslated ?? 0} ترجمه‌نشده`
                        : 'همه موارد انجام شده',
                    color: 'text-pmai-primary',
                  },
                  {
                    label: 'نیاز به کار (کل سایت)',
                    value: needsWork,
                    hint:
                      initialNeedsWork > 0
                        ? `شروع این اجرا: ${initialNeedsWork}`
                        : null,
                    color: needsWork > 0 ? 'text-amber-700' : 'text-green-700',
                  },
                  {
                    label: 'ترجمه‌شده / ناقص / خام',
                    value: `${liveStats.translated ?? '—'} / ${liveStats.partial ?? '—'} / ${liveStats.untranslated ?? '—'}`,
                    hint: liveStats.total != null ? `از ${liveStats.total} محتوای قابل ترجمه` : null,
                    color: 'text-gray-800',
                  },
                  { label: 'موفق قطعی (این اجرا)', value: job?.succeeded ?? 0, hint: 'فقط مواردی که ۱۰۰٪ کامل و یک‌بار شمرده شده‌اند', color: 'text-green-700' },
                  { label: 'ناقص در صف اجرا', value: job?.partial ?? 0, color: 'text-amber-700' },
                  { label: 'ناموفق (این اجرا)', value: job?.failed ?? 0, color: 'text-red-700' },
                  { label: 'رد شده (این اجرا)', value: job?.skipped ?? 0, color: 'text-amber-800' },
                  {
                    label: 'صف سنگین / معوق',
                    value: queueBacklog,
                    hint: 'محصولات با تنوع زیاد یا ناقص که بعداً دوباره گرفته می‌شوند',
                    color: 'text-blue-700',
                  },
                  { label: 'تلاش API (slice)', value: steps, color: 'text-gray-700' },
                  {
                    label: 'پیشرفت بودجه این اجرا',
                    value: queueTotal > 0 ? `${runDone} / ${queueTotal}` : '—',
                    hint: 'موفق قطعی + ردشده‌های تمام‌شده',
                    color: 'text-pmai-primary',
                  },
                  { label: 'باقی بودجه این اجرا', value: runRemaining, color: runRemaining > 0 ? 'text-blue-700' : 'text-green-700' },
                ].map((item) => (
                  <div key={item.label} className="rounded-lg bg-gray-50 px-3 py-2">
                    <p className="text-xs text-pmai-muted">{item.label}</p>
                    <p className={`text-lg font-bold ${item.color ?? ''}`}>{item.value}</p>
                    {item.hint ? <p className="mt-0.5 text-[11px] text-pmai-muted">{item.hint}</p> : null}
                  </div>
                ))}
              </div>

              {initialNeedsWork > 0 && (
                <div className="mb-3">
                  <div className="mb-1 flex justify-between text-xs text-pmai-muted">
                    <span>پیشرفت کل سایت (از شروع این اجرا)</span>
                    <span>
                      {siteResolved} / {initialNeedsWork} ({siteProgress}%)
                    </span>
                  </div>
                  <div className="h-3 overflow-hidden rounded-full bg-gray-200">
                    <div
                      className={`h-full rounded-full transition-all duration-300 ${
                        isRunning ? 'bg-pmai-primary animate-pulse' : 'bg-pmai-primary'
                      }`}
                      style={{ width: `${Math.min(100, siteProgress)}%` }}
                    />
                  </div>
                  <p className="mt-2 text-xs text-pmai-muted">
                    کل سایت الان: {liveStats.translated ?? 0} ترجمه‌شده، {liveStats.partial ?? 0} ناقص،{' '}
                    {liveStats.untranslated ?? 0} ترجمه‌نشده
                    {queueTotal > 0
                      ? ` — این اجرا (۱۰۰٪ تمام‌شده): ${runDone}/${queueTotal} (${progress}%)`
                      : ''}
                  </p>
                </div>
              )}

              {!initialNeedsWork && queueTotal > 0 && (
                <div className="mb-3">
                  <div className="mb-1 flex justify-between text-xs text-pmai-muted">
                    <span>پیشرفت این اجرا</span>
                    <span>
                      {runDone} / {queueTotal} ({progress}%)
                    </span>
                  </div>
                  <div className="h-3 overflow-hidden rounded-full bg-gray-200">
                    <div
                      className={`h-full rounded-full transition-all duration-300 ${
                        isRunning ? 'bg-pmai-primary animate-pulse' : 'bg-pmai-primary'
                      }`}
                      style={{ width: `${Math.min(100, progress)}%` }}
                    />
                  </div>
                </div>
              )}

              <p className="text-xs text-pmai-muted">
                شروع: {formatTime(job?.started_at)} — آخرین به‌روزرسانی: {formatTime(job?.updated_at)}
              </p>

              {job?.last_error && (
                <div className="mt-2 space-y-2">
                  <p className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    آخرین خطا: {job.last_error}
                  </p>
                  {canSkip && (
                    <button
                      type="button"
                      onClick={handleSkip}
                      disabled={isSkipDisabled}
                      className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50"
                    >
                      {actionPending === 'skip'
                        ? 'در حال رد کردن…'
                        : `رد کردن #${skipTargetId} و ادامه با مورد بعدی`}
                    </button>
                  )}
                </div>
              )}

              {Array.isArray(job?.stalled_details) && job.stalled_details.length > 0 && (
                <div className="mt-2 space-y-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                  <p className="font-medium">جزئیات موارد ناقص:</p>
                  {job.stalled_details.map((item) => (
                    <div key={item.post_id} className="rounded border border-amber-100 bg-white/70 px-2 py-2">
                      <p className="font-medium">
                        #{item.post_id} {item.title ? `(${item.title})` : ''}
                        {item.status ? ` — ${item.status}` : ''}
                      </p>
                      {(item.fields ?? []).length > 0 ? (
                        <ul className="mt-1 list-inside list-disc text-xs">
                          {item.fields.map((field) => (
                            <li key={`${item.post_id}-${field.key ?? field.label}`}>
                              {field.translated ? '✓' : '✗'} {field.label}
                              {field.meta_key ? (
                                <span className="font-mono text-[11px] text-amber-800"> ({field.meta_key})</span>
                              ) : null}
                            </li>
                          ))}
                        </ul>
                      ) : (
                        <p className="mt-1 text-xs">
                          {(item.missing ?? []).join('، ') || (item.notes ?? []).join('، ') || 'فیلد نامشخص'}
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </>
          )}
        </div>
      </div>

      <div className="rounded-lg border border-pmai-border bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-base font-semibold text-gray-900">گزارش لحظه‌ای</h3>
          <button
            type="button"
            onClick={loadJob}
            className="inline-flex cursor-pointer items-center gap-1 text-xs text-pmai-primary hover:underline"
          >
            <HiArrowPath className="h-3.5 w-3.5" />
            به‌روزرسانی
          </button>
        </div>
        <div
          ref={logsRef}
          className="max-h-72 overflow-y-auto rounded border border-gray-100 bg-gray-50 p-3 font-mono text-xs leading-relaxed"
        >
          {logs.length === 0 ? (
            <p className="text-pmai-muted">هنوز فعالیتی ثبت نشده.</p>
          ) : (
            logs.map((entry) => (
              <p
                key={entry.id}
                className={
                  entry.type === 'success'
                    ? 'text-green-800'
                    : entry.type === 'error'
                      ? 'text-red-700'
                      : entry.type === 'warning'
                        ? 'text-amber-800'
                        : 'text-gray-700'
                }
              >
                {entry.message}
              </p>
            ))
          )}
        </div>
      </div>

      <aside className="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950">
        <h3 className="font-semibold text-blue-900">نکات مهم</h3>
        <ul className="mt-2 list-inside list-disc space-y-1">
          <li>کار ترجمه روی سرور با کرون انجام می‌شود؛ این صفحه مانیتور و کنترل است.</li>
          <li>گزارش لحظه‌ای از لاگ سرور همین اجرا پر می‌شود — اگر متوقف شد «ادامه» یا «به‌روزرسانی» را بزنید.</li>
          <li>اگر یک مورد گیر کرد، «رد کردن» را بزنید تا کرون به مورد بعدی برود.</li>
          <li>
            برای ترجمه ساده‌تر بدون resume، از{' '}
            <a href={`${adminUrls.settings}#bulk`} className="font-semibold underline">
              تنظیمات → ترجمه گروهی
            </a>{' '}
            استفاده کنید.
          </li>
        </ul>
      </aside>
    </Layout>
  );
}
