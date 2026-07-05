import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import LanguageSelect from './components/ui/LanguageSelect';
import { useTargetLanguages } from './hooks/useTargetLanguages';
import { fetchJob, jobAction, jobStep, refreshJobStats, JOB_STEP_TIMEOUT_MS } from './api/job';
import { HiBolt, HiArrowPath } from './components/ui/icons';

const STEP_DELAY_MS = 250;
const POLL_INTERVAL_MS = 2000;
const DEFERRED_BACKOFF_MS = [500, 1000, 2000, 4000, 8000, 12000];
const MAX_IDLE_STEPS = 8;

function jobProgressMarker(job) {
  if (!job || typeof job !== 'object') {
    return '';
  }

  const done = job.run_done ?? job.succeeded ?? 0;
  const steps = job.steps ?? job.processed ?? 0;
  const postId = job.last_step?.post_id ?? job.current_post?.post_id ?? '';

  return `${done}:${steps}:${postId}`;
}

function stepErrorMessage(error) {
  const status = error?.response?.status;
  const apiMessage = error?.response?.data?.message;
  const code = error?.code;

  if (code === 'ECONNABORTED' || /timeout/i.test(error?.message || '')) {
    return `پاسخ سرور بیش از ${Math.round(JOB_STEP_TIMEOUT_MS / 60000)} دقیقه طول کشید (صفحات Elementor سنگین یا API کند). وضعیت ذخیره شد — «ادامه» را بزنید.`;
  }

  if (apiMessage) {
    return apiMessage;
  }

  if (status >= 500) {
    return 'خطای سرور هنگام ترجمه. وضعیت ذخیره شد — «ادامه» را بزنید.';
  }

  if (!error?.response) {
    return 'اتصال قطع شد. وضعیت ذخیره شد — «ادامه» را بزنید.';
  }

  return 'مرحله ترجمه ناموفق بود. وضعیت ذخیره شد — «ادامه» را بزنید.';
}

const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};

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
  const [processing, setProcessing] = useState(false);
  const [refreshingStats, setRefreshingStats] = useState(false);
  const [notice, setNotice] = useState(null);
  const [stepWaitSec, setStepWaitSec] = useState(0);
  const [activePost, setActivePost] = useState(null);
  const runningRef = useRef(false);
  const autoResumedRef = useRef(false);
  const logsRef = useRef(null);
  const isActiveRef = useRef(true);
  const pendingTimersRef = useRef(new Set());

  const clearPendingTimers = useCallback(() => {
    pendingTimersRef.current.forEach((timerId) => {
      window.clearTimeout(timerId);
      window.clearInterval(timerId);
    });
    pendingTimersRef.current.clear();
  }, []);

  const delay = useCallback((ms) => {
    return new Promise((resolve, reject) => {
      const timerId = window.setTimeout(() => {
        pendingTimersRef.current.delete(timerId);

        if (!isActiveRef.current) {
          reject(new Error('unmounted'));
          return;
        }

        resolve();
      }, ms);

      pendingTimersRef.current.add(timerId);
    });
  }, []);

  useEffect(() => {
    isActiveRef.current = true;

    return () => {
      isActiveRef.current = false;
      runningRef.current = false;
      clearPendingTimers();
    };
  }, [clearPendingTimers]);

  const appendLog = useCallback((message, type = 'info') => {
    if (!message) {
      return;
    }

    setLogs((prev) => [...prev, { id: `${Date.now()}-${Math.random()}`, message, type }]);
  }, []);

  const appendStepLog = useCallback(
    (step) => {
      const message = formatStepLog(step);

      if (!message) {
        return;
      }

      const type =
        step.status === 'translated'
          ? 'success'
          : step.status === 'failed'
            ? 'error'
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
      setJob(data);

      if (data?.lang && data.status !== 'idle') {
        setTargetLang(data.lang);
      }

      return data;
    } catch {
      setNotice({ type: 'error', message: 'بارگذاری وضعیت ترجمه خودکار ناموفق بود.' });
      return null;
    }
  }, [setTargetLang]);

  useEffect(() => {
    loadJob().finally(() => setLoading(false));
  }, [loadJob]);

  useEffect(() => {
    if (logsRef.current) {
      logsRef.current.scrollTop = logsRef.current.scrollHeight;
    }
  }, [logs]);

  const runSteps = useCallback(async () => {
    if (runningRef.current || !isActiveRef.current) {
      return;
    }

    runningRef.current = true;

    if (isActiveRef.current) {
      setProcessing(true);
      setStepWaitSec(0);
    }

    try {
      let current = await fetchJob();

      if (!isActiveRef.current) {
        return;
      }

      setJob(current);
      let idleSteps = 0;
      let lastMarker = jobProgressMarker(current);

      while (current?.status === 'running' && isActiveRef.current) {
        const waitStarted = Date.now();
        const waitTimer = window.setInterval(() => {
          if (!isActiveRef.current) {
            return;
          }

          setStepWaitSec(Math.floor((Date.now() - waitStarted) / 1000));
        }, 1000);
        pendingTimersRef.current.add(waitTimer);

        try {
          current = await jobStep();
        } finally {
          window.clearInterval(waitTimer);
          pendingTimersRef.current.delete(waitTimer);

          if (isActiveRef.current) {
            setStepWaitSec(0);
          }
        }

        if (!isActiveRef.current) {
          return;
        }

        if (!current || typeof current !== 'object' || !current.status) {
          throw new Error('پاسخ نامعتبر از سرور دریافت شد.');
        }

        setJob(current);

        if (current.current_post?.title) {
          setActivePost(current.current_post);
        } else if (current.last_step?.title || current.last_step?.post_id) {
          setActivePost({
            post_id: current.last_step.post_id,
            title: current.last_step.title || `#${current.last_step.post_id}`,
            step_status: current.last_step.status,
            step_message: current.last_step.message,
          });
        }

        if (current.last_step) {
          appendStepLog(current.last_step);
        }

        if (current.last_error && current.status !== 'paused') {
          appendLog(current.last_error, 'error');
        }

        if (current.status === 'paused') {
          if (current.pause_reason === 'critical') {
            appendLog('ترجمه به دلیل خطای جدی API متوقف شد. پس از رفع مشکل «ادامه» را بزنید.', 'warning');
          } else if (current.last_error) {
            appendLog(current.last_error, 'warning');
          } else {
            appendLog('ترجمه متوقف شد — «ادامه» را بزنید.', 'warning');
          }

          if (Array.isArray(current.stalled_details) && current.stalled_details.length) {
            current.stalled_details.forEach((item) => {
              const pending = (item.fields ?? []).filter((field) => !field.translated);
              if (pending.length) {
                pending.forEach((field) => {
                  appendLog(
                    `#${item.post_id} ${item.title ?? ''} — ${field.label} (${field.meta_key ?? field.key})`,
                    'warning'
                  );
                });
              } else {
                appendLog(
                  `#${item.post_id} ${item.title ?? ''} — ${(item.missing ?? []).join('، ') || (item.notes ?? []).join('، ') || 'نامشخص'}`,
                  'warning'
                );
              }
            });
          }
          break;
        }

        if (current.status === 'completed') {
          appendLog('ترجمه خودکار با موفقیت به پایان رسید.', 'success');
          break;
        }

        const marker = jobProgressMarker(current);
        const madeProgress = Boolean(current.last_step) || marker !== lastMarker;

        if (current.step_deferred || !madeProgress) {
          idleSteps += 1;

          if (current.step_deferred_message && idleSteps === 1) {
            appendLog(current.step_deferred_message, 'warning');
          }

          if (idleSteps >= MAX_IDLE_STEPS) {
            const stallMessage =
              current.step_deferred_reason === 'step_lock_busy'
                ? 'ترجمه در تب یا فرآیند دیگری در حال اجراست — برای جلوگیری از فشار به سرور متوقف شد. «ادامه» را بزنید.'
                : 'چند تلاش پشت‌سرهم بدون پیشرفت — برای جلوگیری از فشار به سرور متوقف شد. «ادامه» را بزنید.';

            appendLog(stallMessage, 'warning');
            setNotice({ type: 'warning', message: stallMessage });

            try {
              const paused = await jobAction('pause');
              setJob(paused);
            } catch {
              // Server state remains authoritative.
            }
            break;
          }

          const delayMs = current.step_deferred
            ? DEFERRED_BACKOFF_MS[Math.min(idleSteps - 1, DEFERRED_BACKOFF_MS.length - 1)]
            : STEP_DELAY_MS;

          try {
            await delay(delayMs);
          } catch {
            return;
          }
          continue;
        }

        idleSteps = 0;
        lastMarker = marker;

        try {
          await delay(STEP_DELAY_MS);
        } catch {
          return;
        }
      }
    } catch (error) {
      if (!isActiveRef.current || error?.message === 'unmounted') {
        return;
      }

      const message = stepErrorMessage(error);
      appendLog(message, 'warning');
      setNotice({ type: 'warning', message });

      try {
        const paused = await jobAction('pause');

        if (isActiveRef.current) {
          setJob(paused);
        }
      } catch {
        // Job state remains on the server; user can still resume/stop.
      }
    } finally {
      runningRef.current = false;

      if (!isActiveRef.current) {
        return;
      }

      setProcessing(false);
      setStepWaitSec(0);
      const latest = await loadJob();

      if (!isActiveRef.current) {
        return;
      }

      if (latest?.status !== 'running') {
        setActivePost(latest?.current_post?.title ? latest.current_post : null);
      }
    }
  }, [appendLog, appendStepLog, delay, loadJob]);

  useEffect(() => {
    if (!job || job.status !== 'running') {
      return undefined;
    }

    const timer = window.setInterval(() => {
      if (!isActiveRef.current) {
        return;
      }

      fetchJob()
        .then((data) => {
          if (!data || !isActiveRef.current) {
            return;
          }

          if (data.current_post?.title) {
            setActivePost(data.current_post);
          }

          setJob((prev) => {
            if (!prev || !runningRef.current) {
              return data;
            }

            // While a step is in flight, only refresh the current-post banner.
            return {
              ...prev,
              current_post: data.current_post ?? prev.current_post,
              updated_at: data.updated_at ?? prev.updated_at,
              step_started_at: data.step_started_at ?? prev.step_started_at,
            };
          });
        })
        .catch(() => {
          // Ignore poll errors while a step request is authoritative.
        });
    }, POLL_INTERVAL_MS);

    return () => window.clearInterval(timer);
  }, [job?.status]);

  useEffect(() => {
    if (loading || autoResumedRef.current || processing) {
      return;
    }

    if (job?.status !== 'running') {
      return;
    }

    autoResumedRef.current = true;

    const stepAge =
      job.step_started_at != null ? Math.floor(Date.now() / 1000) - Number(job.step_started_at) : null;

    // A step is already in flight on the server — pause so the UI matches server reality.
    if (stepAge != null && stepAge >= 0 && stepAge < 30) {
      appendLog(
        'اجرای قبلی هنوز روی سرور فعال بود — برای جلوگیری از تداخل متوقف شد. برای ادامه «ادامه» را بزنید.',
        'warning'
      );
      jobAction('pause')
        .then((data) => setJob(data))
        .catch(() => {});
      return;
    }

    // Stale in-flight marker: previous browser tab died mid-step.
    if (stepAge != null && stepAge >= 30) {
      appendLog('مرحله قبلی بیش از حد طول کشید یا قطع شد. برای ادامه «ادامه» را بزنید.', 'warning');
      jobAction('pause')
        .then((data) => setJob(data))
        .catch(() => {});
      return;
    }

    appendLog('اجرای ناتمام پیدا شد — ادامه خودکار…');
    runSteps();
  }, [loading, job?.status, job?.step_started_at, processing, runSteps, appendLog]);

  const handleStart = async () => {
    setNotice(null);
    setLogs([]);
    setActivePost(null);
    autoResumedRef.current = true;
    appendLog(`در حال آماده‌سازی صف ترجمه برای ${targetLabel}…`);

    try {
      const data = await jobAction('start', targetLang);
      setJob(data);
      appendLog(`ترجمه خودکار شروع شد — ${data.total ?? 0} مورد در صف (${targetLabel}).`);
      await runSteps();
    } catch (error) {
      const message = error?.response?.data?.message || 'شروع ترجمه خودکار ناموفق بود.';
      setNotice({ type: 'error', message });
      appendLog(message, 'error');
    }
  };

  const handleResume = async () => {
    setNotice(null);

    try {
      const data = await jobAction('resume');
      setJob(data);
      appendLog('ترجمه از سر گرفته شد.');
      await runSteps();
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'ادامه ناموفق بود.' });
    }
  };

  const handlePause = async () => {
    try {
      const data = await jobAction('pause');
      setJob(data);
      appendLog('ترجمه متوقف موقت شد.');
    } catch {
      setNotice({ type: 'error', message: 'توقف موقت ناموفق بود.' });
    }
  };

  const handleStop = async () => {
    try {
      const data = await jobAction('stop');
      setJob(data);
      appendLog('ترجمه متوقف شد.');
    } catch {
      setNotice({ type: 'error', message: 'توقف ناموفق بود.' });
    }
  };

  const handleRefreshStats = async () => {
    setRefreshingStats(true);
    setNotice(null);

    try {
      const data = await refreshJobStats(targetLang);
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
  const isRunning = job?.status === 'running';
  const isPaused = job?.status === 'paused';
  const isOutdated = Boolean(job?.outdated);
  const isOrphanedRunning = isRunning && !processing && needsWork > 0;
  const canResume = (isPaused || isOrphanedRunning) && needsWork > 0 && !processing;
  const canStart = !loading && !processing && !isRunning && !langsLoading;
  const activeLangLabel = job?.lang_label || targetLabel;
  const displayPost = activePost?.title ? activePost : job?.current_post;
  const lastStepStatus = displayPost?.step_status || job?.last_step?.status;

  const statusLabel = isRunning
    ? processing
      ? stepWaitSec > 0
        ? `در حال ترجمه… (${stepWaitSec}ث)`
        : 'در حال ترجمه…'
      : 'نیاز به ادامه (اجرا ناتمام)'
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
      subtitle={`ترجمه مرحله‌به‌مرحله محتوای فارسی به ${targetLabel} — با قابلیت توقف و ادامه`}
    >
      <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
        <p className="font-medium">روش پیشنهادی برای ترجمه انبوه</p>
        <p className="mt-1">
          «پیشرفت این اجرا» فقط همین run را نشان می‌دهد. «کل سایت» بعد از هر ترجمه موفق به‌روز می‌شود —
          اگر مورد ناقص بماند یا فقط بخشی از فیلدها پر شود، عدد کل سایت کمتر از پیشرفت این اجرا جلو نمی‌رود.
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
            disabled={isRunning || processing}
            className="mb-4 w-full"
          />

          <div className="space-y-2">
            <button
              type="button"
              onClick={handleStart}
              disabled={!canStart || langOptions.length === 0}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-pmai-primary px-4 py-2.5 text-sm font-medium text-white hover:bg-pmai-primary-dark disabled:cursor-not-allowed disabled:opacity-50"
            >
              <HiBolt className="h-4 w-4" />
              {needsWork > 0 ? `شروع ترجمه (${needsWork} مورد)` : 'شروع ترجمه خودکار'}
            </button>
            {canResume && (
              <button
                type="button"
                onClick={handleResume}
                disabled={processing}
                className="w-full cursor-pointer rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-sm font-medium text-green-800 hover:bg-green-100 disabled:cursor-not-allowed disabled:opacity-50"
              >
                ادامه ({needsWork} باقی‌مانده)
              </button>
            )}
            {isRunning && (
              <button
                type="button"
                onClick={handlePause}
                disabled={processing}
                className="w-full cursor-pointer rounded-lg border border-pmai-border px-4 py-2 text-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
              >
                توقف موقت
              </button>
            )}
            {(isRunning || isPaused) && (
              <button
                type="button"
                onClick={handleStop}
                className="w-full cursor-pointer rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 hover:bg-red-100"
              >
                توقف کامل
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
            {(isRunning || processing) && (
              <span className="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800">
                <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500" />
                در حال پردازش
              </span>
            )}
          </div>

          {loading ? (
            <p className="text-pmai-muted">در حال بارگذاری…</p>
          ) : (
            <>
              {processing && (
                <div
                  className={`mb-4 rounded-lg border px-4 py-3 text-sm ${
                    lastStepStatus === 'partial'
                      ? 'border-amber-200 bg-amber-50 text-amber-950'
                      : 'border-blue-200 bg-blue-50 text-blue-900'
                  }`}
                >
                  <p className={`text-xs ${lastStepStatus === 'partial' ? 'text-amber-700' : 'text-blue-700'}`}>
                    {displayPost?.title
                      ? lastStepStatus === 'partial'
                        ? 'تلاش مجدد برای مورد ناقص'
                        : 'در حال ترجمه'
                      : 'در حال آماده‌سازی مورد بعدی'}
                    {stepWaitSec > 0 ? ` — ${stepWaitSec} ثانیه` : ''}
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
                    <p className="mt-1 text-xs opacity-80">انتخاب پست بعدی از صف…</p>
                  )}
                  {stepWaitSec >= 45 ? (
                    <p className="mt-2 text-xs opacity-80">
                      این مورد طولانی شده (Elementor یا API کند). اگر خطا باشد اینجا نمایش داده می‌شود.
                    </p>
                  ) : null}
                </div>
              )}

              <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[
                  { label: 'وضعیت', value: statusLabel },
                  {
                    label: 'پیشرفت این اجرا',
                    value: queueTotal > 0 ? `${runDone} / ${queueTotal}` : '—',
                    color: 'text-pmai-primary',
                  },
                  {
                    label: 'باقی این اجرا',
                    value: runRemaining,
                    color: runRemaining > 0 ? 'text-blue-700' : 'text-green-700',
                  },
                  {
                    label: 'نیاز به کار (کل سایت)',
                    value: needsWork,
                    hint:
                      initialNeedsWork > 0
                        ? `شروع: ${initialNeedsWork}${siteResolved > 0 ? ` · −${siteResolved} انجام شد` : ''}`
                        : null,
                    color: needsWork > 0 ? 'text-amber-700' : 'text-green-700',
                  },
                  { label: 'موفق (این اجرا)', value: job?.succeeded ?? 0, color: 'text-green-700' },
                  { label: 'ناقص (این اجرا)', value: job?.partial ?? 0, color: 'text-amber-700' },
                  { label: 'ناموفق (این اجرا)', value: job?.failed ?? 0, color: 'text-red-700' },
                  { label: 'در صف تلاش مجدد', value: runPending, color: 'text-blue-700' },
                  { label: 'تلاش API', value: steps, color: 'text-gray-700' },
                  { label: 'کل محتوای قابل ترجمه', value: liveStats.total ?? '—' },
                ].map((item) => (
                  <div key={item.label} className="rounded-lg bg-gray-50 px-3 py-2">
                    <p className="text-xs text-pmai-muted">{item.label}</p>
                    <p className={`text-lg font-bold ${item.color ?? ''}`}>{item.value}</p>
                    {item.hint ? <p className="mt-0.5 text-[11px] text-pmai-muted">{item.hint}</p> : null}
                  </div>
                ))}
              </div>

              {queueTotal > 0 && (
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
                        isRunning || processing ? 'bg-pmai-primary animate-pulse' : 'bg-pmai-primary'
                      }`}
                      style={{ width: `${Math.min(100, progress)}%` }}
                    />
                  </div>
                  {liveStats.translated != null && (
                    <p className="mt-2 text-xs text-pmai-muted">
                      کل سایت: {liveStats.translated ?? 0} ترجمه‌شده، {liveStats.partial ?? 0} ناقص،{' '}
                      {liveStats.untranslated ?? 0} ترجمه‌نشده
                      {siteResolved > 0 ? ` — ${siteResolved} مورد از شروع این اجرا تکمیل شد` : ''}
                    </p>
                  )}
                </div>
              )}

              <p className="text-xs text-pmai-muted">
                شروع: {formatTime(job?.started_at)} — آخرین به‌روزرسانی: {formatTime(job?.updated_at)}
              </p>

              {job?.last_error && (
                <p className="mt-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                  آخرین خطا: {job.last_error}
                </p>
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
          <li>هر مورد جداگانه ترجمه می‌شود تا از فشار به سرور جلوگیری شود.</li>
          <li>اگر API موفق باشد ولی ترجمه ناقص بماند، همان مورد دوباره در صف قرار می‌گیرد.</li>
          <li>اگر شبکه قطع شود، وضعیت ذخیره می‌شود و با «ادامه» از همانجا ادامه می‌یابد.</li>
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
