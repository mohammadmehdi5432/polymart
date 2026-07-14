import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import LanguageSelect from './components/ui/LanguageSelect';
import { useTargetLanguages } from './hooks/useTargetLanguages';
import { fetchJob, jobAction, jobStep, refreshJobStats, abortJobStep, testTranslationApi, fetchRemainingWork } from './api/job';
import { HiBolt, HiArrowPath } from './components/ui/icons';

const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};
const aiConfigured = Boolean(config.aiConfigured);

const POLL_INTERVAL_MS = 2000;
/** Between-batch idle before the monitor kicks ensure (seconds). */
const CRON_STALE_SEC = 45;
/**
 * Lock alone is trusted this long without a completed tick.
 * Must stay above max AI HTTP timeout (~165s) so the monitor does not steal a living worker.
 */
const LOCK_HEALTHY_SEC = 200;
/** Between-item lock with no progress — force ensure. */
const LOCK_IDLE_STALE_SEC = 30;
/** Do not hammer ensure more than once per this interval. */
const ENSURE_MIN_INTERVAL_MS = 3000;
const AUTO_RUN_STORAGE_KEY = 'polymart_ai_autotranslate_autorun';
const POLL_ERROR_NOTICE_THRESHOLD = 3;

function latestWorkerStamp(job, includeScheduled = false) {
  return Math.max(
    Number(job?.last_cron_at || 0),
    Number(job?.worker_heartbeat_at || 0),
    Number(job?.partial_progress_at || 0),
    includeScheduled ? Number(job?.worker_scheduled_at || 0) : 0
  );
}

function isElementorPartialJob(job) {
  return (
    job?.status === 'running' &&
    (Boolean(job?.elementor_priority) ||
      Boolean(job?.elementor_gap_fill_pending) ||
      (job?.partial_phase === 'elementor' && Boolean(job?.partial_post_id)))
  );
}

function apiCooldownRemaining(job) {
  return Math.max(0, Number(job?.api_cooldown_remaining || 0));
}

function isApiCooldownActive(job) {
  return job?.status === 'running' && apiCooldownRemaining(job) > 0;
}

function formatApiCooldownLabel(seconds, providerLabel = 'API') {
  if (seconds >= 60) {
    return `API ${providerLabel} محدود — ${Math.ceil(seconds / 60)} دقیقه تا ادامه`;
  }
  return `API ${providerLabel} محدود — ${seconds} ثانیه تا ادامه`;
}

function isSyntheticCooldownMessage(message) {
  const text = String(message || '');
  return /API .+ محدود|API آروان محدود|تا ادامه|بدون مصرف توکن|API آماده تلاش مجدد/i.test(text);
}

function resolveAiProviderLabel(job) {
  return job?.ai_provider_label || config.aiProviderLabel || 'آروان‌کلاد';
}

function resolveAiProviderId(job) {
  return job?.ai_provider || config.aiProvider || 'arvan';
}

/** Top status line while Elementor chunks are in flight (lock is only held a few seconds per tick). */
function formatElementorPartialStatus(job, cronAgeSec, workerLabel) {
  const progress = job?.partial_progress || '';
  const parsed = progress && /^(\d+)\/(\d+)$/.exec(progress);

  if (parsed && Number(parsed[1]) >= Number(parsed[2])) {
    if (job?.worker_lock) {
      return `Elementor ${progress} — تکمیل نهایی${workerLabel ? ` (${workerLabel})` : ''}`;
    }

    if (cronAgeSec == null || cronAgeSec < CRON_STALE_SEC) {
      return `Elementor ${progress} — تکمیل نهایی / مورد بعدی…`;
    }

    return null;
  }

  const suffix = progress ? ` — ${progress}` : '';
  const who = workerLabel ? ` (${workerLabel})` : '';

  if (job?.worker_lock) {
    return `در حال ترجمه Elementor${suffix}${who}`;
  }

  if (cronAgeSec == null) {
    return `ادامه Elementor${suffix}`;
  }

  if (cronAgeSec < 12) {
    return `ادامه Elementor${suffix} — آماده‌سازی بخش بعدی`;
  }

  if (cronAgeSec < CRON_STALE_SEC) {
    return `ادامه Elementor${suffix} — منتظر تیک کارگر (${cronAgeSec}ث)`;
  }

  return null;
}

function stepLogSignature(step) {
  if (!step) {
    return '';
  }

  // Ignore `time` — partial rows refresh each poll with the same message.
  if (step.status === 'partial' || step.status === 'deferred') {
    return `${step.post_id ?? ''}:${step.status ?? ''}:${step.message ?? ''}`;
  }

  return `${step.post_id ?? ''}:${step.status ?? ''}:${step.time ?? ''}:${step.message ?? ''}`;
}

function isCronHealthy(job) {
  const now = Math.floor(Date.now() / 1000);
  const lastActivity = latestWorkerStamp(job);
  const activityAge = lastActivity > 0 ? now - lastActivity : Number.POSITIVE_INFINITY;
  const lockAge = Number(job?.worker_lock_age || 0);
  const hasActivePost = Boolean(job?.current_post?.title && !job?.current_post?.from_last_step);
  const progressAt = Number(job?.partial_progress_at || 0);
  const progressAge = progressAt > 0 ? now - progressAt : Number.POSITIVE_INFINITY;
  const elementorStalled =
    Boolean(job?.elementor_progress_stalled) ||
    (job?.partial_phase === 'elementor' &&
      job?.partial_post_id &&
      progressAge > 90 &&
      activityAge > 90);

  const elementorPartial = isElementorPartialJob(job);
  const progressMatch = (job?.partial_progress || '').match(/^(\d+)\/(\d+)$/);
  const elementorBatchesDone =
    progressMatch &&
    Number(progressMatch[1]) >= Number(progressMatch[2]) &&
    Number(progressMatch[2]) > 0;
  const gapFillPending = Boolean(job?.elementor_gap_fill_pending);

  if (job?.status === 'running' && (gapFillPending || (elementorBatchesDone && elementorPartial)) && activityAge > 4) {
    return false;
  }

  if (job?.status === 'running' && gapFillPending && activityAge > 35) {
    return false;
  }

  if (job?.status === 'running' && isApiCooldownActive(job)) {
    return true;
  }

  if (job?.status === 'running' && (job?.as_slice_active || job?.as_running) && activityAge < 180) {
    return true;
  }

  if (job?.status === 'running' && elementorStalled) {
    return false;
  }

  // AS action queued but worker silent — wake the queue quickly (metabox pattern).
  if (job?.status === 'running' && job?.as_pending && activityAge > 3) {
    return false;
  }

  if (job?.status === 'running' && job?.elementor_priority && activityAge > 5) {
    return false;
  }

  // Between elementor chunks the PHP lock is released — nudge ensure sooner than generic idle.
  if (job?.status === 'running' && !job?.worker_lock && activityAge > (elementorPartial ? 6 : 18)) {
    return false;
  }

  if (job?.worker_lock) {
    // Actively translating a known post — trust the lock for one AI-call window.
    if (hasActivePost && lockAge >= 0 && lockAge < LOCK_HEALTHY_SEC) {
      return true;
    }

    // Between items: only unhealthy when BOTH lock and activity are stale.
    // Fresh lock + last completed item is normal (picking next), not a stall.
    if (!hasActivePost) {
      if (activityAge < LOCK_IDLE_STALE_SEC || (lockAge >= 0 && lockAge < LOCK_IDLE_STALE_SEC)) {
        return true;
      }
      return false;
    }

    return lockAge >= 0 && lockAge < LOCK_HEALTHY_SEC && activityAge < LOCK_HEALTHY_SEC;
  }

  if (!lastActivity) {
    return false;
  }

  return activityAge < CRON_STALE_SEC;
}

function jobStepHeadline(lastStepStatus, displayPost, job) {
  const cooldownRemaining = apiCooldownRemaining(job);
  const cooldownActive = isApiCooldownActive(job);

  if (cooldownActive) {
    const progress = job?.partial_progress || displayPost?.partial_progress;
    const phase = job?.partial_phase || displayPost?.partial_phase;
    const stepMsg = displayPost?.step_message || job?.last_step?.message || '';

    if (lastStepStatus === 'partial' && phase === 'elementor' && progress) {
      const parsed = /^(\d+)\/(\d+)$/.exec(progress);
      if (parsed && Number(parsed[1]) >= Number(parsed[2])) {
        return `Elementor ${progress} — تکمیل فیلدهای باقی‌مانده (${formatApiCooldownLabel(cooldownRemaining, resolveAiProviderLabel(job))})`;
      }
      return `Elementor ${progress} — ${formatApiCooldownLabel(cooldownRemaining, resolveAiProviderLabel(job))}`;
    }

    if (stepMsg && !isSyntheticCooldownMessage(stepMsg)) {
      return formatApiCooldownLabel(cooldownRemaining, resolveAiProviderLabel(job));
    }

    return formatApiCooldownLabel(cooldownRemaining, resolveAiProviderLabel(job));
  }

  const lockAge = Number(job?.worker_lock_age || 0);
  const now = Math.floor(Date.now() / 1000);
  const activityAge = Math.max(
    0,
    now - latestWorkerStamp(job)
  );
  const hasActivePost = Boolean(displayPost?.title && !displayPost?.from_last_step);

  if (job?.worker_lock && lockAge > LOCK_HEALTHY_SEC && activityAge > LOCK_HEALTHY_SEC) {
    return 'قفل مرحله منقضی شده — در حال بازیابی…';
  }

  if (job?.worker_lock && hasActivePost) {
    return 'کارگر سرور در حال ترجمه است';
  }

  // Lock + last finished item is normal between ticks — not a deadlock.
  if (job?.worker_lock && displayPost?.from_last_step && job?.status === 'running') {
    if (activityAge >= LOCK_IDLE_STALE_SEC && lockAge >= LOCK_IDLE_STALE_SEC) {
      return 'کارگر بین دو مورد متوقف شد — در حال بیدار کردن…';
    }
    return 'در حال انتخاب مورد بعدی…';
  }

  if (displayPost?.from_last_step) {
    if (job?.status === 'running') {
      return 'آخرین مورد تمام شد — آماده‌سازی مورد بعدی';
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
    const parsed = progress && /^(\d+)\/(\d+)$/.exec(progress);
    const gapMsg = job?.last_step?.message || displayPost?.step_message || '';
    const gapFillActive =
      Boolean(job?.elementor_gap_fill_pending) || /باقی|تکمیل نهایی/i.test(gapMsg);

    if (parsed && Number(parsed[1]) >= Number(parsed[2]) && gapFillActive) {
      return progress
        ? `Elementor ${progress} — تکمیل فیلدهای باقی‌مانده…`
        : 'تکمیل فیلدهای Elementor…';
    }

    if (parsed && Number(parsed[1]) >= Number(parsed[2])) {
      return `Elementor تمام شد (${parsed[2]}/${parsed[2]}) — مورد بعدی…`;
    }
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

  // Never keep a previous "active" post as if the worker still owns it — that
  // made isCronHealthy trust a dead lock and skip recovery for minutes.
  if (!merged.current_post?.title) {
    const fromLast = resolveDisplayPost(merged, null);
    if (fromLast?.title) {
      merged.current_post = fromLast;
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

function formatTime(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('fa-IR');
}

function partialProgressKey(job) {
  if (!job?.partial_post_id || !job?.partial_progress) {
    return '';
  }

  return `${job.partial_post_id}:${job.partial_phase || ''}:${job.partial_progress}`;
}

function formatStepLog(step) {
  if (!step) {
    return null;
  }

  const prefix = step.title ? `#${step.post_id} «${step.title}»` : `#${step.post_id}`;
  return step.message || `${prefix} — ${step.status}`;
}

function remainingStatusLabel(status) {
  switch (status) {
    case 'translated':
      return 'کامل';
    case 'partial':
      return 'ناقص';
    case 'untranslated':
      return 'ترجمه‌نشده';
    default:
      return status || '—';
  }
}

function remainingStatusClass(status) {
  switch (status) {
    case 'translated':
      return 'bg-green-100 text-green-800';
    case 'partial':
      return 'bg-amber-100 text-amber-900';
    case 'untranslated':
      return 'bg-gray-100 text-gray-700';
    default:
      return 'bg-gray-100 text-gray-700';
  }
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
  const [remainingWork, setRemainingWork] = useState(null);
  const [remainingLoading, setRemainingLoading] = useState(false);
  const [remainingPage, setRemainingPage] = useState(1);
  const autoResumedRef = useRef(false);
  const lastStepLogRef = useRef('');
  const lastPartialProgressRef = useRef('');
  const seenServerLogIdsRef = useRef(new Set());
  const lastWorkerTickLogAtRef = useRef(0);
  const pollErrorCountRef = useRef(0);
  const lastEnsureErrorAtRef = useRef(0);
  const logsRef = useRef(null);
  const logsPinnedRef = useRef(false);
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

  const syncServerLogs = useCallback((recentLogs, replace = false) => {
    if (!Array.isArray(recentLogs) || recentLogs.length === 0) {
      return;
    }

    if (replace) {
      seenServerLogIdsRef.current = new Set();
    }

    // Server sends newest-first; append oldest→newest.
    const chronological = [...recentLogs].reverse();
    const incoming = [];

    chronological.forEach((entry) => {
      const raw = String(entry.message || '').trim();
      if (!raw) {
        return;
      }

      const dedupeKey = raw.replace(/\s+/g, ' ');
      const id = entry.id || `srv-${entry.time}-${dedupeKey}`;

      if (seenServerLogIdsRef.current.has(id)) {
        return;
      }

      seenServerLogIdsRef.current.add(id);
      incoming.push({
        id,
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

    if (incoming.length === 0) {
      return;
    }

    setLogs((prev) => {
      if (replace) {
        return incoming.slice(-200);
      }

      const merged = [...prev];

      incoming.forEach((entry) => {
        if (!merged.some((item) => item.id === entry.id)) {
          merged.push(entry);
        }
      });

      return merged.slice(-200);
    });
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

      // Prefer server recent_logs — skip synthetic cooldown echoes from last_step.
      if (step.status === 'translated' || step.status === 'skipped') {
        return;
      }

      if (isSyntheticCooldownMessage(step.message)) {
        return;
      }

      const type =
        step.status === 'failed'
          ? 'error'
          : step.status === 'deferred'
            ? 'warning'
          : step.status === 'partial'
            ? (step.message?.includes('مسدود') || step.message?.includes('محدود') ? 'warning' : 'info')
            : 'info';

      appendLog(message, type);
    },
    [appendLog]
  );

  const loadJob = useCallback(async () => {
    try {
      const data = await fetchJob({ initial: true });
      pollErrorCountRef.current = 0;
      const merged = mergeJobSnapshot(null, data);
      setJob(merged);

      const display = resolveDisplayPost(merged, activePostRef.current);
      if (display?.title) {
        activePostRef.current = display;
        setActivePost(display);
      }

      if (Array.isArray(data?.recent_logs)) {
        syncServerLogs(data.recent_logs, true);
        lastPartialProgressRef.current = partialProgressKey(merged);
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

  const loadRemainingWork = useCallback(
    async (page = 1) => {
      if (!targetLang) {
        return;
      }

      setRemainingLoading(true);

      try {
        const data = await fetchRemainingWork({
          lang: targetLang,
          postType: 'page',
          page,
          perPage: 20,
        });
        setRemainingWork(data);
        setRemainingPage(page);
      } catch (error) {
        setNotice({
          type: 'warning',
          message:
            error?.response?.data?.message ||
            'بارگذاری لیست برگه‌های باقی‌مانده ناموفق بود.',
        });
      } finally {
        setRemainingLoading(false);
      }
    },
    [targetLang]
  );

  useEffect(() => {
    if (loading || langsLoading) {
      return;
    }

    loadRemainingWork(1);
  }, [loading, langsLoading, loadRemainingWork]);

  useEffect(() => {
    const el = logsRef.current;
    if (!el || logsPinnedRef.current) {
      return;
    }
    el.scrollTop = el.scrollHeight;
  }, [logs]);

  const handleLogsScroll = useCallback(() => {
    const el = logsRef.current;
    if (!el) {
      return;
    }
    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    logsPinnedRef.current = distanceFromBottom > 48;
  }, []);

  const trackLiveJobEvents = useCallback(
    (data) => {
      if (!data?.last_step) {
        return;
      }

      appendStepLog(data.last_step);
    },
    [appendStepLog]
  );

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

    trackLiveJobEvents(data);

    if (Array.isArray(data.recent_logs) && data.recent_logs.length > 0) {
      syncServerLogs(data.recent_logs);
    }

    return merged;
  }, [syncServerLogs, trackLiveJobEvents]);

  /**
   * Lightweight: unlock/schedule/ping only. Never run AI inline from the poll loop
   * (that was freezing the whole admin/site for ~60s per request).
   */
  const ensureServerWorker = useCallback(async () => {
    try {
      const data = await jobAction('ensure');
      lastEnsureErrorAtRef.current = 0;
      if (isActiveRef.current) {
        applyJobUpdate(data);
      }
      return data;
    } catch (error) {
      const now = Date.now();
      if (
        isActiveRef.current &&
        now - lastEnsureErrorAtRef.current >= 30000
      ) {
        lastEnsureErrorAtRef.current = now;
        const message =
          error?.response?.data?.message ||
          'بیدار کردن کارگر ناموفق بود؛ کرون پشتیبان دوباره تلاش می‌کند.';
        appendLog(message, 'warning');
        setNotice({ type: 'warning', message });
      }
      return null;
    }
  }, [applyJobUpdate, appendLog]);

  useEffect(() => {
    if (!job || job.status !== 'running') {
      return undefined;
    }

    writeAutoRunFlag(true);

    let ensureInFlight = false;
    let lastEnsureAt = 0;

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

        const now = Math.floor(Date.now() / 1000);
        const activityAge = Math.max(0, now - latestWorkerStamp(data));
        const elementorNeedsKick =
          isElementorPartialJob(data) &&
          !data?.as_slice_active &&
          !data?.as_running &&
          Number(data?.api_cooldown_remaining || 0) <= 0 &&
          (Boolean(data?.elementor_gap_fill_pending)
            ? activityAge > 5
            : activityAge > 8);

        if (data.status === 'running' && (!isCronHealthy(data) || elementorNeedsKick) && !ensureInFlight) {
          const nowMs = Date.now();
          if (nowMs - lastEnsureAt < ENSURE_MIN_INTERVAL_MS) {
            return;
          }

          const lockAge = Number(data.worker_lock_age || 0);
          const hasActivePost = Boolean(
            data.current_post?.title && !data.current_post?.from_last_step
          );
          const lockBlocks =
            !isElementorPartialJob(data) &&
            !Boolean(data?.elementor_gap_fill_pending) &&
            Boolean(data.worker_lock) &&
            hasActivePost &&
            lockAge >= 0 &&
            lockAge < LOCK_HEALTHY_SEC &&
            activityAge < LOCK_HEALTHY_SEC &&
            !Boolean(data.elementor_progress_stalled);

          if (!lockBlocks) {
            ensureInFlight = true;
            lastEnsureAt = nowMs;
            try {
              const useHeavyTick =
                !data?.as_slice_active &&
                !data?.as_running &&
                (activityAge >= 60 ||
                  isElementorPartialJob(data) ||
                  activityAge >= CRON_STALE_SEC ||
                  elementorNeedsKick ||
                  Boolean(data.elementor_progress_stalled));
              const recovered = useHeavyTick
                ? await jobStep().catch(() => ensureServerWorker())
                : await ensureServerWorker();
              if (recovered?.worker_direct_tick && isActiveRef.current) {
                appendLog('اجرای مستقیم Elementor — صف AS دور زده شد.', 'success');
              } else if (recovered?.worker_kicked && isActiveRef.current) {
                appendLog('تیک kick اجرا شد — صف دوباره جلو می‌رود.', 'success');
              } else if (recovered?.worker_inline_tick && isActiveRef.current) {
                appendLog('تیک بازیابی اجرا شد — صف دوباره جلو می‌رود.', 'success');
              } else if (recovered?.lock_recovered && isActiveRef.current) {
                appendLog('قفل گیرکرده آزاد شد — تیک بعدی شروع می‌شود…', 'warning');
              } else if (recovered?.worker_recovered_at && isActiveRef.current) {
                appendLog('کارگر پس از سکوت بازیابی شد — ادامه ترجمه…', 'warning');
              } else if (recovered?.loopback_spawned && isActiveRef.current) {
                appendLog('کارگر پس‌زمینه بیدار شد — ادامه صف…', 'info');
              }

              const tickJob = recovered || data;
              const nowMs = Date.now();

              if (
                tickJob?.status === 'running' &&
                nowMs - lastWorkerTickLogAtRef.current >= 15000
              ) {
                lastWorkerTickLogAtRef.current = nowMs;

                if (isElementorPartialJob(tickJob)) {
                  appendLog(
                    `تیک کارگر — Elementor ${tickJob.partial_progress || '…'} (#${tickJob.partial_post_id})`,
                    'info'
                  );
                } else if (tickJob?.worker_direct_tick) {
                  appendLog('تیک کارگر — اجرای مستقیم Elementor', 'info');
                } else if (tickJob?.worker_inline_tick) {
                  appendLog('تیک کارگر — inline', 'info');
                }
              }

              if (recovered && isActiveRef.current) {
                applyJobUpdate(recovered);
              }
            } finally {
              ensureInFlight = false;
            }
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
    lastPartialProgressRef.current = '';
    seenServerLogIdsRef.current = new Set();
    lastWorkerTickLogAtRef.current = 0;
    setActionPending('start');
    appendLog(`در حال آماده‌سازی صف ترجمه برای ${targetLabel}…`);

    const runStart = async () => {
      const data = await jobAction('start', targetLang);
      setJob(data);
      autoResumedRef.current = true;
      writeAutoRunFlag(true);
      appendLog(
        `ترجمه خودکار شروع شد — ${data.total ?? 0} مورد در صف (${targetLabel}). کارگر کرون روی سرور اجرا می‌شود.`,
        'success'
      );
      void jobAction('ensure', targetLang)
        .then((kicked) => {
          if (kicked) {
            applyJobUpdate(kicked);
          }
        })
        .catch(() => {});
      return data;
    };

    try {
      await runStart();
    } catch (error) {
      const code = error?.response?.data?.code;
      const isTimeout =
        error?.code === 'ECONNABORTED' || /timeout/i.test(error?.message || '');
      let message =
        error?.response?.data?.message ||
        (error?.message === 'Network Error'
          ? 'درخواست شروع قطع شد — صفحه را رفرش کنید؛ اگر وضعیت «در حال اجرا» است کارگر روی سرور شروع شده.'
          : 'شروع ترجمه خودکار ناموفق بود.');

      if (isTimeout) {
        let recovered = null;

        for (let attempt = 0; attempt < 6; attempt += 1) {
          recovered = await loadJob();

          if (recovered?.status === 'running') {
            break;
          }

          await new Promise((resolve) => {
            window.setTimeout(resolve, 1500 * (attempt + 1));
          });
        }

        if (recovered?.status === 'running') {
          autoResumedRef.current = true;
          writeAutoRunFlag(true);
          appendLog(
            `ترجمه روی سرور شروع شد — ${recovered.total ?? 0} مورد در صف (${targetLabel}).`,
            'success'
          );
          await ensureServerWorker();
          return;
        }

        message =
          'درخواست شروع طولانی شد — اگر وضعیت «در حال اجرا» نیست دوباره تلاش کنید.';
      }

      if (code === 'polymart_ai_job_running') {
        const existing = await loadJob();
        const workerStamp = latestWorkerStamp(existing);
        const workerAge = workerStamp
          ? Math.max(0, Math.floor(Date.now() / 1000) - workerStamp)
          : Number.POSITIVE_INFINITY;
        const staleRunning =
          existing?.status === 'running' &&
          !existing?.worker_lock &&
          !existing?.as_running &&
          workerAge > LOCK_HEALTHY_SEC;

        if (staleRunning) {
          appendLog('اجرای قبلی روی سرور گیر کرده بود — پاک‌سازی و شروع مجدد…', 'warning');
          try {
            await jobAction('stop', targetLang);
            await runStart();
            return;
          } catch (retryError) {
            message =
              retryError?.response?.data?.message ||
              retryError?.message ||
              message;
          }
        } else if (existing?.status === 'running') {
          setNotice({
            type: 'info',
            message: 'یک ترجمه از قبل در حال اجراست — مانیتور همین اجرا را نشان می‌دهد.',
          });
          await ensureServerWorker();
          return;
        }
      }

      setNotice({ type: 'error', message });
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
      await loadRemainingWork(1);
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

    const providerLabel = resolveAiProviderLabel(job);

    try {
      const data = await testTranslationApi({ text: apiTestText.trim() || 'سلام دنیا', lang: targetLang });
      setApiTestResult(data);

      const resultProvider = data?.ai_provider_label || data?.provider_label || providerLabel;

      if (data?.success) {
        appendLog(
          `تست ${resultProvider} موفق (${data.elapsed_ms}ms): «${data.source}» → «${data.translated}»`,
          'success'
        );

        if (data?.cooldown_cleared) {
          appendLog('تست موفق — توقف API برداشته شد، کار ادامه می‌یابد.', 'success');
          const refreshed = await fetchJob();
          setJob(refreshed);
        }
      } else {
        appendLog(`تست ${resultProvider} ناموفق (${data?.elapsed_ms ?? '?'}ms): ${data?.error || 'خطای نامشخص'}`, 'error');
      }
    } catch (error) {
      const message = error?.response?.data?.message || error?.message || 'تست API ناموفق بود.';
      setApiTestResult({ success: false, error: message });
      appendLog(`تست ${providerLabel} ناموفق: ${message}`, 'error');
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
  const aiProviderLabel = resolveAiProviderLabel(job);
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
    const stamp = latestWorkerStamp(job);
    if (!stamp) {
      return null;
    }
    return Math.max(0, Math.floor(Date.now() / 1000) - stamp);
  })();
  const latestActivityAt = latestWorkerStamp(job);
  const cooldownActive = isApiCooldownActive(job);

  const workerLabel =
    job?.last_worker === 'as'
      ? 'Action Scheduler'
      : job?.last_worker === 'cli'
        ? 'Action Scheduler'
        : job?.last_worker === 'cron'
          ? 'کرون keep-alive'
          : job?.last_worker === 'loopback'
            ? 'Action Scheduler'
            : job?.last_worker === 'admin'
              ? 'سرور'
              : null;

  const statusLabel = actionPendingLabel
    ? actionPendingLabel
    : isRunning
      ? (() => {
          if (isApiCooldownActive(job)) {
            const sec = apiCooldownRemaining(job);
            if (sec >= 60) {
              return `انتظار محدودیت API — ${Math.ceil(sec / 60)} دقیقه (بدون مصرف توکن)`;
            }
            return `انتظار محدودیت API — ${sec} ثانیه`;
          }

          if (!isApiCooldownActive(job) && isElementorPartialJob(job)) {
            const elementorStatus = formatElementorPartialStatus(job, cronAgeSec, workerLabel);
            if (elementorStatus) {
              return elementorStatus;
            }
          }

          if (job?.worker_lock && cronAgeSec != null && cronAgeSec < LOCK_HEALTHY_SEC) {
            return `در حال ترجمه${workerLabel ? ` (${workerLabel})` : ''}`;
          }

          if (cronAgeSec != null) {
            if (job?.as_pending && cronAgeSec >= 10 && cronAgeSec < CRON_STALE_SEC) {
              return `در صف — بیدار کردن کارگر (${cronAgeSec}ث)`;
            }
            if (cronAgeSec < CRON_STALE_SEC) {
              if (cronAgeSec >= 18) {
                return `بین دو batch — تیک بازیابی (${cronAgeSec}ث)`;
              }
              return `در حال اجرا — آخرین تیک ${cronAgeSec}ث پیش`;
            }
            if (cronAgeSec < 25) {
              return `بین دو تیک — بیدار کردن کارگر (${cronAgeSec}ث)`;
            }
            return `کارگر متوقف شد (${cronAgeSec}ث) — در حال اجرای تیک بازیابی…`;
          }

          return job?.cron_scheduled
            ? 'زمان‌بندی شده — شروع زنجیره پس‌زمینه'
            : 'در حال اجرا روی سرور';
        })()
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
        <p className="font-medium">صف Action Scheduler (موتور پس‌زمینه ووکامرس)</p>
        <p className="mt-1 text-pmai-muted">
          هر دسته ترجمه به‌صورت Action در صف رسمی وردپرس/ووکامرس ثبت می‌شود. کرون‌جاب ۱ دقیقه‌ای سرور فقط
          همین صف را تیک می‌زند — بدون CLI و بدون HTTP Loopback.
        </p>
        <p className="mt-1">
          بستن این تب صف را متوقف نمی‌کند. ووکامرس باید فعال باشد تا Action Scheduler در دسترس باشد.
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
            message={
              resolveAiProviderId(job) === 'gapgpt'
                ? 'کلید API گپ GPT در تنظیمات ترجمه تنظیم نشده — ابتدا از بخش تنظیمات پیکربندی کنید.'
                : 'کلید API یا آدرس AI Gateway در تنظیمات ترجمه تنظیم نشده — ابتدا از بخش تنظیمات پیکربندی کنید.'
            }
          />
        </div>
      )}

      {config.cronDisabled ? (
        <div className="mb-4">
          <Notice
            type="info"
            message="DISABLE_WP_CRON فعال است — ترجمه با Action Scheduler جلو می‌رود و crontab هر دقیقه صف AS را تیک می‌زند (بدون CLI و بدون loopback)."
          />
        </div>
      ) : !config.devMode ? (
        <div className="mb-4">
          <Notice
            type="warning"
            message="پیشنهاد پروداکشن: define('DISABLE_WP_CRON', true) به‌همراه crontab برای wp-cron.php — زنجیرهٔ سریع پلاگین خودش صف را جلو می‌برد."
          />
        </div>
      ) : null}

      {!loading && job?.status === 'running' && (job?.last_cron_at || job?.worker_heartbeat_at) ? (
        <div className="mb-4">
          <Notice
            type="info"
            message={`کارگر سرور زنده است — آخرین فعالیت: ${formatTime(
              latestWorkerStamp(job)
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
            <p className="text-xs font-medium text-gray-800">تست سریع {aiProviderLabel}</p>
            <p className="mt-1 text-xs text-gray-600">
              همان مسیر ترجمه واقعی ({aiProviderLabel}) — اگر اینجا timeout بخورد، مشکل از API است نه از محصول.
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
              {apiTestLoading
                ? `در حال ارسال به ${aiProviderLabel}… (تا ۲ دقیقه)`
                : `تست ترجمه ${aiProviderLabel} → ${targetLabel}`}
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
                    {apiTestResult.ai_provider_label ? (
                      <p className="mt-1 opacity-80">سرویس: {apiTestResult.ai_provider_label}</p>
                    ) : null}
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
                    cooldownActive
                      ? 'border-violet-200 bg-violet-50 text-violet-950'
                      : lastStepStatus === 'partial'
                        ? 'border-amber-200 bg-amber-50 text-amber-950'
                        : 'border-blue-200 bg-blue-50 text-blue-900'
                  }`}
                >
                  <p
                    className={`text-xs ${
                      cooldownActive
                        ? 'text-violet-700'
                        : lastStepStatus === 'partial'
                          ? 'text-amber-700'
                          : lastStepStatus === 'deferred'
                            ? 'text-violet-700'
                            : 'text-blue-700'
                    }`}
                  >
                    {jobStepHeadline(lastStepStatus, displayPost, job)}
                  </p>
                  {displayPost?.title ? (
                    <>
                      <p className="font-medium">
                        #{displayPost.post_id} — {displayPost.title}
                      </p>
                      {(() => {
                        const stepMsg = displayPost.step_message || job?.last_step?.message || '';

                        if (cooldownActive) {
                          if (stepMsg && !isSyntheticCooldownMessage(stepMsg)) {
                            return <p className="mt-1 text-xs opacity-90">{stepMsg}</p>;
                          }

                          return (
                            <p className="mt-1 text-xs opacity-90">
                              بدون مصرف توکن — پس از اتمام محدودیت، ترجمه از همان نقطه ادامه می‌یابد
                            </p>
                          );
                        }

                        return stepMsg ? (
                          <p className="mt-1 text-xs opacity-90">{stepMsg}</p>
                        ) : null;
                      })()}
                    </>
                  ) : (
                    <p className="mt-1 text-xs opacity-80">
                      {job?.worker_lock
                        ? 'قفل مرحله روی سرور فعال است — منتظر اتمام slice…'
                        : 'بین دو مرحله / انتخاب مورد بعدی…'}
                    </p>
                  )}
                  {latestActivityAt ? (
                    <p className="mt-2 text-xs opacity-80">
                      آخرین فعالیت کارگر: {formatTime(latestActivityAt)}
                      {job.last_cron_steps ? ` · ${job.last_cron_steps} مرحله` : ''}
                      {cooldownActive
                        ? ' · منتظر محدودیت API'
                        : job.worker_lock
                          ? ' · قفل فعال'
                          : ''}
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

              {job?.last_error && !(cooldownActive && isSyntheticCooldownMessage(job.last_error)) && (
                <div className="mt-2 space-y-2">
                  <p
                    className={`rounded border px-3 py-2 text-sm ${
                      cooldownActive
                        ? 'border-violet-200 bg-violet-50 text-violet-900'
                        : 'border-red-200 bg-red-50 text-red-800'
                    }`}
                  >
                    {cooldownActive ? 'وضعیت API' : 'آخرین خطا'}: {job.last_error}
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
                      {item.status === 'translated' && item.reason ? (
                        <p className="mt-1 text-xs font-medium text-amber-900">
                          هشدار: سیستم «ترجمه‌شده» می‌گوید ولی فروشگاه هنوز فارسی دارد — {item.reason}
                        </p>
                      ) : null}
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
                          {item.reason
                            || (item.missing ?? []).join('، ')
                            || (item.notes ?? []).join('، ')
                            || 'فیلد نامشخص'}
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

      <div className="mb-6 rounded-lg border border-pmai-border bg-white p-5">
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 className="text-base font-semibold text-gray-900">برگه‌های باقی‌مانده</h3>
            <p className="mt-1 text-xs text-pmai-muted">
              برگه‌هایی که برای <strong>{targetLabel}</strong> هنوز ترجمه کامل ندارند — روی «ویرایش برگه» برو
              و از متاباکس پلی‌مارت اسکن و ترجمه را انجام بده.
            </p>
          </div>
          <button
            type="button"
            onClick={() => loadRemainingWork(remainingPage)}
            disabled={remainingLoading || langsLoading}
            className="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-pmai-border px-3 py-1.5 text-xs hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <HiArrowPath className={`h-3.5 w-3.5 ${remainingLoading ? 'animate-spin' : ''}`} />
            {remainingLoading ? 'در حال بارگذاری…' : 'به‌روزرسانی لیست'}
          </button>
        </div>

        {remainingLoading && !remainingWork ? (
          <p className="text-sm text-pmai-muted">در حال اسکن برگه‌ها…</p>
        ) : remainingWork?.total > 0 ? (
          <>
            <p className="mb-3 text-sm text-gray-700">
              <strong>{remainingWork.total}</strong> برگه نیاز به کار دارد
              {remainingWork.pages > 1
                ? ` — صفحه ${remainingWork.page} از ${remainingWork.pages}`
                : ''}
            </p>
            <ul className="space-y-3">
              {(remainingWork.items || []).map((item) => (
                <li
                  key={item.post_id}
                  className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-gray-900">
                        #{item.post_id} — {item.title || '(بدون عنوان)'}
                      </p>
                      <div className="mt-1 flex flex-wrap items-center gap-2">
                        <span
                          className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${remainingStatusClass(item.status)}`}
                        >
                          {remainingStatusLabel(item.status)}
                        </span>
                        {item.uses_elementor ? (
                          <span className="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">
                            Elementor
                          </span>
                        ) : null}
                      </div>
                      {item.gap_reason ? (
                        <p className="mt-2 text-xs text-gray-600">{item.gap_reason}</p>
                      ) : null}
                      {Array.isArray(item.missing) && item.missing.length > 0 ? (
                        <p className="mt-1 text-xs text-amber-800">
                          فیلدهای مانده: {item.missing.join('، ')}
                        </p>
                      ) : null}
                    </div>
                    <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                      {item.edit_url ? (
                        <a
                          href={item.edit_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center justify-center rounded-lg border border-pmai-primary bg-white px-3 py-1.5 text-xs font-medium text-pmai-primary hover:bg-blue-50"
                        >
                          ویرایش برگه
                        </a>
                      ) : null}
                      {item.view_url_lang ? (
                        <a
                          href={item.view_url_lang}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100"
                        >
                          پیش‌نمایش {targetLabel}
                        </a>
                      ) : null}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
            {remainingWork.pages > 1 ? (
              <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                <button
                  type="button"
                  onClick={() => loadRemainingWork(Math.max(1, remainingPage - 1))}
                  disabled={remainingLoading || remainingPage <= 1}
                  className="rounded-lg border border-pmai-border px-3 py-1.5 text-xs hover:bg-gray-50 disabled:opacity-50"
                >
                  قبلی
                </button>
                <span className="text-xs text-pmai-muted">
                  صفحه {remainingPage} از {remainingWork.pages}
                </span>
                <button
                  type="button"
                  onClick={() => loadRemainingWork(Math.min(remainingWork.pages, remainingPage + 1))}
                  disabled={remainingLoading || remainingPage >= remainingWork.pages}
                  className="rounded-lg border border-pmai-border px-3 py-1.5 text-xs hover:bg-gray-50 disabled:opacity-50"
                >
                  بعدی
                </button>
              </div>
            ) : null}
          </>
        ) : (
          <p className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            همه برگه‌ها برای {targetLabel} ترجمه کامل دارند.
          </p>
        )}
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
          onScroll={handleLogsScroll}
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
