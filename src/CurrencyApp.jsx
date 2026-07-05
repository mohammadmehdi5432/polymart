import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import StatCard from './components/ui/StatCard';
import { SkeletonCard } from './components/ui/LoadingSkeleton';
import {
  fetchCurrencyStatus,
  refreshCurrencyRate,
  fetchSyncJob,
  syncJobAction,
  syncJobStep,
} from './api/currency';
import { fetchSettings, saveSettings } from './api/settings';
import { useUnsavedWarning } from './hooks/useUnsavedWarning';
import {
  HiArrowPath,
  HiCheckCircle,
  HiClock,
  HiCloudArrowDown,
  HiCurrencyDollar,
  HiExclamationTriangle,
  HiPause,
  HiPlay,
} from './components/ui/icons';

const inputClassName =
  'w-full rounded-lg border border-pmai-border px-3 py-2.5 text-sm transition focus:border-pmai-primary focus:outline-none focus:ring-2 focus:ring-pmai-primary/20';

const STEP_DELAY_MS = 50;

function formatUsdFromToman(toman, rate) {
  if (!rate || rate <= 0) return '—';
  return (toman / rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ProgressBar({ value }) {
  const pct = Math.max(0, Math.min(100, Number(value) || 0));

  return (
    <div className="h-3 w-full overflow-hidden rounded-full bg-gray-100">
      <div
        className="h-full rounded-full bg-pmai-primary transition-all duration-300"
        style={{ width: `${pct}%` }}
        role="progressbar"
        aria-valuenow={pct}
        aria-valuemin={0}
        aria-valuemax={100}
      />
    </div>
  );
}

export default function CurrencyApp() {
  const [status, setStatus] = useState(null);
  const [syncStats, setSyncStats] = useState(null);
  const [job, setJob] = useState(null);
  const [apiKeySet, setApiKeySet] = useState(false);
  const [apiKeyInput, setApiKeyInput] = useState('');
  const [savedApiKeyInput, setSavedApiKeyInput] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [refreshingRate, setRefreshingRate] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [notice, setNotice] = useState(null);
  const [logs, setLogs] = useState([]);
  const runningRef = useRef(false);
  const logsRef = useRef(null);

  const isDirty = !loading && apiKeyInput !== savedApiKeyInput;
  useUnsavedWarning(isDirty);

  const appendLog = useCallback((message, type = 'info') => {
    if (!message) return;
    setLogs((prev) => [...prev.slice(-80), { id: `${Date.now()}-${Math.random()}`, message, type }]);
  }, []);

  const loadAll = useCallback(async () => {
    const [statusData, syncData, settingsData] = await Promise.all([
      fetchCurrencyStatus(),
      fetchSyncJob(),
      fetchSettings(),
    ]);

    setStatus(statusData);
    setSyncStats(syncData.stats ?? statusData.sync ?? null);
    setJob(syncData.job ?? statusData.sync_job ?? null);
    setApiKeySet(Boolean(settingsData?.currency?.api_key_set));
    setApiKeyInput('');
    setSavedApiKeyInput('');
  }, []);

  useEffect(() => {
    loadAll()
      .catch(() => setNotice({ type: 'error', message: 'بارگذاری اطلاعات نرخ ارز ناموفق بود.' }))
      .finally(() => setLoading(false));
  }, [loadAll]);

  useEffect(() => {
    if (logsRef.current) {
      logsRef.current.scrollTop = logsRef.current.scrollHeight;
    }
  }, [logs]);

  const handleSaveApiKey = async () => {
    setSaving(true);
    setNotice(null);
    try {
      const payload = { currency: {} };
      if (apiKeyInput.trim()) payload.currency.api_key = apiKeyInput.trim();
      await saveSettings(payload);
      await loadAll();
      setNotice({ type: 'success', message: 'کلید API ذخیره شد.' });
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'ذخیره کلید API ناموفق بود.' });
    } finally {
      setSaving(false);
    }
  };

  const handleRefreshRate = async () => {
    setRefreshingRate(true);
    setNotice(null);
    setLogs([]);
    try {
      const result = await refreshCurrencyRate();
      setStatus(result.status);
      if (result.job) {
        setJob(result.job);
      }
      await loadAll();

      if (result.sync_started && result.job?.status === 'running') {
        setNotice({ type: 'success', message: result.message || 'نرخ به‌روزرسانی شد — تبدیل قیمت‌ها آغاز شد.' });
        appendLog('تبدیل خودکار قیمت‌ها با نرخ جدید…', 'info');
        await runSyncSteps(result.job);
      } else {
        setNotice({ type: result.success ? 'success' : 'warning', message: result.message });
      }
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'دریافت نرخ ارز ناموفق بود.' });
    } finally {
      setRefreshingRate(false);
    }
  };

  const runSyncSteps = useCallback(async (initialJob = null) => {
    if (runningRef.current) return;
    runningRef.current = true;
    setSyncing(true);

    try {
      let current = initialJob ?? job;

      while (current?.status === 'running') {
        const result = await syncJobStep();
        current = result.job;
        setJob(current);
        setSyncStats(result.stats);

        if (current?.last_step?.message) {
          const type = current.last_step.status === 'failed' ? 'error' : 'success';
          appendLog(current.last_step.message, type);
        }

        if (current?.status !== 'running') {
          break;
        }

        await new Promise((r) => setTimeout(r, STEP_DELAY_MS));
      }

      if (current?.status === 'completed') {
        setNotice({ type: 'success', message: 'تبدیل قیمت همه محصولات با موفقیت انجام شد.' });
        appendLog('تبدیل قیمت‌ها تکمیل شد.', 'success');
      }

      await loadAll();
    } catch (error) {
      const message = error?.response?.data?.message || 'مرحله تبدیل قیمت ناموفق بود.';
      setNotice({ type: 'error', message });
      appendLog(message, 'error');
      await loadAll();
    } finally {
      runningRef.current = false;
      setSyncing(false);
    }
  }, [appendLog, job, loadAll]);

  const handleStartSync = async () => {
    setNotice(null);
    setLogs([]);

    if (!status?.rate) {
      setNotice({ type: 'warning', message: 'ابتدا نرخ دلار را دریافت و ذخیره کنید.' });
      return;
    }

    try {
      const result = await syncJobAction('start');
      setJob(result.job);
      setSyncStats(result.stats);
      appendLog('شروع تبدیل قیمت محصولات…', 'info');
      await runSyncSteps(result.job);
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'شروع تبدیل قیمت ناموفق بود.' });
    }
  };

  const handlePauseSync = async () => {
    try {
      const result = await syncJobAction('pause');
      setJob(result.job);
      appendLog('تبدیل قیمت متوقف شد.', 'warning');
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'توقف ناموفق بود.' });
    }
  };

  const handleResumeSync = async () => {
    try {
      const result = await syncJobAction('resume');
      setJob(result.job);
      appendLog('ادامه تبدیل قیمت…', 'info');
      await runSyncSteps(result.job);
    } catch (error) {
      setNotice({ type: 'error', message: error?.response?.data?.message || 'ادامه ناموفق بود.' });
    }
  };

  const hasRate = status?.rate != null && status.rate > 0;
  const jobRunning = job?.status === 'running';
  const jobPaused = job?.status === 'paused';
  const jobCompleted = job?.status === 'completed';
  const progress = job?.progress_pct ?? 0;
  const apiKeyPlaceholder = apiKeySet ? 'کلید ذخیره شده — برای تغییر، کلید جدید وارد کنید' : 'اختیاری — پیش‌فرض: 123';

  return (
    <Layout title="نرخ ارز" subtitle="دریافت نرخ دلار و تبدیل دسته‌ای قیمت محصولات برای مشتریان انگلیسی و عربی">
      {notice && (
        <div className="mb-5">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      {loading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonCard key={i} />
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="نرخ دلار (تومان)" value={hasRate ? status.rate_formatted : '—'} color={hasRate ? 'text-pmai-primary' : 'text-amber-700'} icon={<HiCurrencyDollar />} />
            <StatCard label="محصولات تبدیل‌شده" value={`${syncStats?.synced_products ?? 0} / ${syncStats?.total_products ?? 0}`} color="text-green-700" icon={<HiCheckCircle />} />
            <StatCard label="آخرین سینک قیمت" value={syncStats?.last_full_sync_human || '—'} icon={<HiClock />} />
            <StatCard label="کرون بعدی" value={status?.cron_scheduled ? (status.cron_next_run_human || 'فعال') : 'غیرفعال'} color={status?.cron_scheduled ? 'text-green-700' : 'text-amber-700'} />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            {/* Step 1: Rate */}
            <section className="rounded-xl border border-pmai-border bg-white p-5 shadow-sm">
              <div className="mb-4 flex items-center gap-2">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-pmai-primary text-xs font-bold text-white">۱</span>
                <h2 className="text-base font-semibold text-gray-900">دریافت نرخ دلار</h2>
              </div>

              {hasRate ? (
                <p className="mb-4 text-3xl font-bold text-gray-900" dir="ltr">
                  {status.rate_formatted}
                  <span className="mr-2 text-sm font-normal text-pmai-muted">IRT / USD</span>
                </p>
              ) : (
                <p className="mb-4 flex items-center gap-2 text-sm text-amber-800">
                  <HiExclamationTriangle className="h-4 w-4" />
                  نرخ دلار هنوز ذخیره نشده
                </p>
              )}

              {hasRate && (
                <p className="mb-4 text-xs text-pmai-muted" dir="ltr">
                  نمونه: 1,000,000 تومان ≈ ${formatUsdFromToman(1000000, status.rate)}
                </p>
              )}

              <button
                type="button"
                onClick={handleRefreshRate}
                disabled={refreshingRate}
                className="flex w-full items-center justify-center gap-2 rounded-lg bg-pmai-primary px-4 py-3 text-sm font-semibold text-white hover:bg-pmai-primary-dark disabled:opacity-60"
              >
                <HiCloudArrowDown className={`h-5 w-5 ${refreshingRate ? 'animate-pulse' : ''}`} />
                {refreshingRate ? 'در حال دریافت…' : 'دریافت و ذخیره نرخ'}
              </button>
            </section>

            {/* Step 2: Price sync */}
            <section className="rounded-xl border border-pmai-border bg-white p-5 shadow-sm">
              <div className="mb-4 flex items-center gap-2">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-xs font-bold text-white">۲</span>
                <h2 className="text-base font-semibold text-gray-900">تبدیل قیمت محصولات</h2>
              </div>

              <p className="mb-4 text-sm text-pmai-muted">
                با هر به‌روزرسانی نرخ، تبدیل قیمت‌ها بلافاصله آغاز می‌شود. کرون روزانه نرخ را می‌گیرد و در پس‌زمینه ادامه می‌دهد.
              </p>

              <div className="mb-3 flex items-center justify-between text-sm">
                <span className="text-pmai-muted">پیشرفت</span>
                <span className="font-medium text-gray-900">
                  {job?.offset ?? 0} / {job?.total ?? syncStats?.total_products ?? 0} ({progress}%)
                </span>
              </div>
              <ProgressBar value={progress} />

              <div className="mt-4 flex flex-wrap gap-2">
                {!jobRunning && !jobPaused && (
                  <button
                    type="button"
                    onClick={handleStartSync}
                    disabled={syncing || !hasRate}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                  >
                    <HiPlay className="h-4 w-4" />
                    {jobCompleted ? 'تبدیل مجدد همه قیمت‌ها' : 'شروع تبدیل قیمت‌ها'}
                  </button>
                )}

                {jobRunning && (
                  <button
                    type="button"
                    onClick={handlePauseSync}
                    disabled={syncing}
                    className="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-900"
                  >
                    <HiPause className="h-4 w-4" />
                    توقف
                  </button>
                )}

                {jobPaused && (
                  <button
                    type="button"
                    onClick={handleResumeSync}
                    disabled={syncing}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white"
                  >
                    <HiPlay className="h-4 w-4" />
                    ادامه
                  </button>
                )}

                {(jobRunning || syncing) && (
                  <span className="inline-flex items-center gap-1 self-center text-sm text-pmai-muted">
                    <HiArrowPath className="h-4 w-4 animate-spin" />
                    در حال پردازش…
                  </span>
                )}
              </div>

              {!hasRate && (
                <p className="mt-3 text-xs text-amber-700">ابتدا مرحله ۱ را انجام دهید.</p>
              )}
            </section>
          </div>

          {/* Live log */}
          <section className="rounded-xl border border-pmai-border bg-white shadow-sm">
            <div className="border-b border-gray-100 px-5 py-3">
              <h3 className="text-sm font-semibold text-gray-900">گزارش زنده تبدیل</h3>
            </div>
            <div ref={logsRef} className="max-h-56 overflow-y-auto px-5 py-3 text-sm">
              {logs.length === 0 ? (
                <p className="py-6 text-center text-pmai-muted">پس از شروع تبدیل، جزئیات هر محصول اینجا نمایش داده می‌شود.</p>
              ) : (
                <ul className="space-y-1.5">
                  {logs.map((entry) => (
                    <li
                      key={entry.id}
                      className={
                        entry.type === 'error'
                          ? 'text-red-700'
                          : entry.type === 'success'
                            ? 'text-green-700'
                            : entry.type === 'warning'
                              ? 'text-amber-800'
                              : 'text-gray-700'
                      }
                      dir="ltr"
                    >
                      {entry.message}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </section>

          <details className="rounded-xl border border-pmai-border bg-white shadow-sm">
            <summary className="cursor-pointer px-5 py-4 text-sm font-semibold text-gray-900">تنظیمات پیشرفته — کلید API (اختیاری)</summary>
            <div className="border-t border-gray-100 px-5 py-4">
              <div className="flex flex-col gap-3 sm:flex-row">
                <input
                  type="password"
                  value={apiKeyInput}
                  onChange={(e) => setApiKeyInput(e.target.value)}
                  placeholder={apiKeyPlaceholder}
                  className={inputClassName}
                  dir="ltr"
                />
                <button
                  type="button"
                  onClick={handleSaveApiKey}
                  disabled={saving || !isDirty}
                  className="shrink-0 rounded-lg border border-pmai-primary px-5 py-2.5 text-sm font-medium text-pmai-primary disabled:opacity-50"
                >
                  {saving ? 'ذخیره…' : 'ذخیره کلید'}
                </button>
              </div>
            </div>
          </details>
        </div>
      )}
    </Layout>
  );
}
