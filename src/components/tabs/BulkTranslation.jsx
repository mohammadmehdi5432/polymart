import { useCallback, useEffect, useRef, useState } from 'react';
import { bulkTranslatePost, fetchUntranslated } from '../../api/bulk';
import LanguageSelect from '../ui/LanguageSelect';
import Notice from '../ui/Notice';
import { useTargetLanguages } from '../../hooks/useTargetLanguages';

const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};

function formatPostLabel(postId, title, postType) {
  const typeLabel = postType === 'product' ? 'محصول' : 'نوشته';
  const name = title ? `«${title}»` : `#${postId}`;
  return `${typeLabel} ${name}`;
}

export default function BulkTranslation() {
  const {
    langOptions,
    targetLang,
    setTargetLang,
    loading: langsLoading,
    error: langsError,
    targetLabel,
  } = useTargetLanguages('en');

  const [siteStats, setSiteStats] = useState(null);
  const [queue, setQueue] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [isRunning, setIsRunning] = useState(false);
  const [isPaused, setIsPaused] = useState(false);
  const [loadingList, setLoadingList] = useState(true);
  const [logs, setLogs] = useState([]);
  const [runStats, setRunStats] = useState({ succeeded: 0, failed: 0 });

  const pauseRef = useRef(false);
  const stopRef = useRef(false);
  const queueRef = useRef([]);
  const initialNeedsWorkRef = useRef(0);

  const [scanCursor, setScanCursor] = useState(0);
  const scanCursorRef = useRef(0);

  const applyListResponse = useCallback((data, afterId = 0) => {
    const ids = data.post_ids ?? [];

    if (data.stats) {
      setSiteStats(data.stats);
    }

    if (afterId > 0) {
      setQueue((prev) => {
        const merged = [...prev, ...ids];
        queueRef.current = merged;
        return merged;
      });
    } else {
      setQueue(ids);
      queueRef.current = ids;
    }

    if (data.scanned_through) {
      scanCursorRef.current = data.scanned_through;
      setScanCursor(data.scanned_through);
    }

    return ids;
  }, []);

  const loadUntranslated = useCallback(async (afterId = 0) => {
    setLoadingList(true);

    try {
      const data = await fetchUntranslated(targetLang, afterId);
      applyListResponse(data, afterId);

      if (data.truncated) {
        setLogs((prev) => [
          ...prev,
          {
            id: Date.now(),
            type: 'info',
            message: `${data.post_ids?.length ?? 0} مورد در این دسته. پس از اتمام، «بارگذاری دسته بعدی» را بزنید.`,
          },
        ]);
      }
    } catch {
      if (afterId === 0) {
        setSiteStats(null);
        setQueue([]);
        queueRef.current = [];
      }
      setLogs((prev) => [
        ...prev,
        { id: Date.now(), type: 'error', message: 'بارگذاری موارد ترجمه‌نشده ناموفق بود.' },
      ]);
    } finally {
      setLoadingList(false);
    }
  }, [applyListResponse, targetLang]);

  useEffect(() => {
    scanCursorRef.current = 0;
    setScanCursor(0);
    loadUntranslated(0);
  }, [loadUntranslated]);

  const appendLog = (message, type = 'info') => {
    setLogs((prev) => [...prev, { id: `${Date.now()}-${Math.random()}`, type, message }]);
  };

  const waitWhilePaused = async () => {
    while (pauseRef.current && !stopRef.current) {
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
  };

  const refreshSiteStats = async () => {
    try {
      const data = await fetchUntranslated(targetLang, 0);
      if (data.stats) {
        setSiteStats(data.stats);
      }
    } catch {
      // Non-fatal during run.
    }
  };

  const processQueue = async (ids) => {
    const initialTotal = ids.length;
    let wasStopped = false;
    let succeeded = 0;
    let failed = 0;

    for (let index = 0; index < ids.length; index += 1) {
      if (stopRef.current) {
        wasStopped = true;
        appendLog('ترجمه گروهی توسط کاربر متوقف شد.', 'info');
        break;
      }

      await waitWhilePaused();

      if (stopRef.current) {
        wasStopped = true;
        appendLog('ترجمه گروهی توسط کاربر متوقف شد.', 'info');
        break;
      }

      const postId = ids[index];
      setCurrentIndex(index + 1);

      try {
        const result = await bulkTranslatePost(postId, targetLang);
        const label = formatPostLabel(result.post_id, result.title, result.post_type);
        appendLog(`${label} — ذخیره شد`, 'success');
        succeeded += 1;
        setRunStats({ succeeded, failed });
        await refreshSiteStats();
      } catch (error) {
        const message =
          error?.response?.data?.message ||
          error?.message ||
          'خطای ناشناخته';
        appendLog(`خطا در #${postId}: ${message}`, 'error');
        failed += 1;
        setRunStats({ succeeded, failed });
      }
    }

    setIsRunning(false);
    setIsPaused(false);
    pauseRef.current = false;
    stopRef.current = false;
    setCurrentIndex(0);

    await loadUntranslated(0);

    if (!wasStopped && initialTotal > 0) {
      appendLog(`ترجمه گروهی تمام شد — ${succeeded} موفق، ${failed} خطا.`, 'info');
    }
  };

  const handleStart = () => {
    if (!queue.length || isRunning) {
      return;
    }

    const needsWork =
      siteStats != null
        ? (siteStats.untranslated ?? 0) + (siteStats.partial ?? 0)
        : queue.length;
    initialNeedsWorkRef.current = needsWork;

    stopRef.current = false;
    pauseRef.current = false;
    setIsPaused(false);
    setIsRunning(true);
    setLogs([]);
    setCurrentIndex(0);
    setRunStats({ succeeded: 0, failed: 0 });

    const ids = [...queue];
    queueRef.current = ids;
    processQueue(ids);
  };

  const handlePause = () => {
    if (!isRunning) {
      return;
    }

    pauseRef.current = !pauseRef.current;
    setIsPaused(pauseRef.current);
    appendLog(pauseRef.current ? 'ترجمه گروهی متوقف شد.' : 'ترجمه گروهی از سر گرفته شد.', 'info');
  };

  const handleStop = () => {
    if (!isRunning) {
      return;
    }

    stopRef.current = true;
    pauseRef.current = false;
    setIsPaused(false);
    appendLog('درخواست توقف — در حال اتمام مورد جاری…', 'info');
  };

  const needsWork =
    siteStats != null
      ? (siteStats.untranslated ?? 0) + (siteStats.partial ?? 0)
      : queue.length;
  const siteResolved =
    initialNeedsWorkRef.current > 0 && isRunning
      ? Math.max(0, initialNeedsWorkRef.current - needsWork)
      : 0;

  const progressTotal = isRunning ? queueRef.current.length || queue.length : queue.length;
  const progressCurrent = isRunning ? currentIndex : 0;
  const progressPercent =
    progressTotal > 0 ? Math.round((progressCurrent / progressTotal) * 100) : 0;

  return (
    <section aria-labelledby="bulk-translation-heading">
      <h2 id="bulk-translation-heading" className="sr-only">
        ترجمه گروهی
      </h2>

      <div className="rounded border border-pmai-border bg-pmai-surface p-4">
        <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
          <p className="font-medium">ترجمه گروهی ساده — برای اجراهای طولانی از «ترجمه خودکار» استفاده کنید</p>
          <p className="mt-1 text-blue-800">
            این تب برای دسته‌های کوچک مناسب است. برای صدها مورد، توقف/ادامه و گزارش دقیق‌تر،{' '}
            <a href={adminUrls.autoTranslate} className="font-semibold text-pmai-primary underline">
              صفحه ترجمه خودکار
            </a>{' '}
            را باز کنید.
          </p>
        </div>

        <p className="mt-0 text-sm text-pmai-muted">
          محصولات و نوشته‌های فارسی که هنوز کامل به {targetLabel} ترجمه نشده‌اند (ترجمه‌نشده یا ناقص).
          هر مورد جداگانه پردازش می‌شود.
        </p>

        <div className="mt-4 flex flex-wrap items-end gap-4">
          {langsError && (
            <div className="w-full">
              <Notice type="warning" message={langsError} />
            </div>
          )}

          <div>
            <label htmlFor="bulk-target-lang" className="mb-1 block text-xs font-medium text-gray-700">
              زبان مقصد
            </label>
            <LanguageSelect
              id="bulk-target-lang"
              value={targetLang}
              onChange={setTargetLang}
              options={langOptions}
              loading={langsLoading}
              disabled={isRunning}
            />
          </div>

          <button
            type="button"
            onClick={() => {
              scanCursorRef.current = 0;
              setScanCursor(0);
              loadUntranslated(0);
            }}
            disabled={loadingList || isRunning}
            className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            به‌روزرسانی
          </button>

          {scanCursor > 0 && (
            <button
              type="button"
              onClick={() => loadUntranslated(scanCursorRef.current)}
              disabled={loadingList || isRunning}
              className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
            >
              بارگذاری دسته بعدی
            </button>
          )}
        </div>

        {siteStats && (
          <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {[
              { label: 'نیاز به کار', value: needsWork, color: needsWork > 0 ? 'text-amber-700' : 'text-green-700' },
              { label: 'ترجمه‌نشده', value: siteStats.untranslated ?? 0, color: 'text-red-700' },
              { label: 'ناقص', value: siteStats.partial ?? 0, color: 'text-amber-700' },
              { label: 'ترجمه‌شده', value: siteStats.translated ?? 0, color: 'text-green-700' },
              { label: 'کل محتوا', value: siteStats.total ?? 0 },
            ].map((item) => (
              <div key={item.label} className="rounded-lg border border-pmai-border bg-gray-50 px-3 py-2">
                <p className="text-xs text-pmai-muted">{item.label}</p>
                <p className={`text-xl font-bold ${item.color ?? 'text-gray-900'}`}>
                  {loadingList ? '…' : item.value}
                </p>
              </div>
            ))}
          </div>
        )}

        {isRunning && (
          <p className="mt-2 text-xs text-pmai-muted">
            این دسته: {progressCurrent} / {progressTotal} — موفق: {runStats.succeeded}، خطا:{' '}
            {runStats.failed}
            {siteResolved > 0 ? ` — ${siteResolved} مورد از کل سایت تکمیل شد` : ''}
          </p>
        )}

        <div className="mt-4 rounded border border-pmai-border bg-gray-50 px-3 py-2 text-xs text-pmai-muted">
          {loadingList ? (
            'در حال بارگذاری صف…'
          ) : (
            <>
              <strong>{queue.length}</strong> مورد در صف این دسته
              {scanCursor > 0 ? ` (ادامه اسکن از #${scanCursor})` : ''}
            </>
          )}
        </div>

        <div className="mt-6 flex flex-wrap gap-3">
          <button
            type="button"
            onClick={handleStart}
            disabled={loadingList || isRunning || queue.length === 0 || langOptions.length === 0 || langsLoading}
            className="rounded bg-pmai-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-pmai-primary-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            شروع ترجمه گروهی ({queue.length})
          </button>

          <button
            type="button"
            onClick={handlePause}
            disabled={!isRunning}
            className="rounded border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isPaused ? 'ادامه' : 'توقف موقت'}
          </button>

          <button
            type="button"
            onClick={handleStop}
            disabled={!isRunning}
            className="rounded border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
          >
            توقف
          </button>
        </div>

        {(isRunning || progressCurrent > 0) && (
          <div className="mt-6">
            <div className="mb-2 flex items-center justify-between text-sm text-pmai-muted">
              <span>پیشرفت این دسته</span>
              <span>
                {progressCurrent} / {progressTotal} ({progressPercent}%)
              </span>
            </div>
            <div className="h-3 w-full overflow-hidden rounded-full bg-gray-200">
              <div
                className="h-full rounded-full bg-pmai-primary transition-all duration-300"
                style={{ width: `${progressPercent}%` }}
              />
            </div>
          </div>
        )}

        <div className="mt-6">
          <label className="mb-2 block text-sm font-medium text-gray-900">گزارش فعالیت</label>
          <div className="max-h-64 overflow-y-auto rounded border border-pmai-border bg-gray-50 p-3 font-mono text-xs leading-relaxed">
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
                        : 'text-gray-700'
                  }
                >
                  {entry.message}
                </p>
              ))
            )}
          </div>
        </div>
      </div>
    </section>
  );
}
