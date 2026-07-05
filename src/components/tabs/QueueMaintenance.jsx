import { useCallback, useState } from 'react';
import { processBackgroundQueues, resyncVariationTranslations } from '../../api/dashboard';
import Notice from '../ui/Notice';
import { HiArrowPath, HiExclamationTriangle } from '../ui/icons';

function queueTotal(queue) {
  if (!queue) {
    return 0;
  }

  return (
    (queue.pending_strings ?? 0)
    + (queue.scheduled_posts ?? 0)
    + (queue.scheduled_terms ?? 0)
  );
}

export default function QueueMaintenance({ queue, cronDisabled, cronUrl, onQueueUpdated }) {
  const [processing, setProcessing] = useState(false);
  const [resyncing, setResyncing] = useState(false);
  const [notice, setNotice] = useState(null);

  const totalQueued = queueTotal(queue);
  const hasWork = totalQueued > 0 || queue?.pending_string_worker_scheduled;

  const handleProcessQueues = useCallback(async () => {
    setProcessing(true);
    setNotice(null);

    try {
      const result = await processBackgroundQueues(20);
      onQueueUpdated?.(result.queue ?? null);
      setNotice({
        type: result.queue && queueTotal(result.queue) > 0 ? 'warning' : 'success',
        message: result.message ?? 'صف‌ها پردازش شدند.',
      });
    } catch {
      setNotice({
        type: 'error',
        message: 'پردازش صف‌ها ناموفق بود. اتصال API و لاگ سرور را بررسی کنید.',
      });
    } finally {
      setProcessing(false);
    }
  }, [onQueueUpdated]);

  const handleResyncVariations = useCallback(async () => {
    setResyncing(true);
    setNotice(null);

    try {
      const result = await resyncVariationTranslations({ dryRun: false, all: true });
      const stats = result.stats ?? {};
      const changed = (stats.title_companion_synced ?? 0)
        + (stats.title_polymart_backfilled ?? 0)
        + (stats.description_companion_synced ?? 0)
        + (stats.description_polymart_backfilled ?? 0);

      setNotice({
        type: 'success',
        message: result.message
          ?? `هماهنگ‌سازی متغیرها انجام شد (${stats.variations_scanned ?? 0} متغیر، ${changed} فیلد).`,
      });
    } catch {
      setNotice({
        type: 'error',
        message: 'هماهنگ‌سازی متغیرها ناموفق بود. لاگ سرور را بررسی کنید.',
      });
    } finally {
      setResyncing(false);
    }
  }, []);

  const busy = processing || resyncing;

  return (
    <div className="rounded-lg border border-pmai-border bg-white p-5 lg:col-span-2">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-gray-900">نگهداری و صف پس‌زمینه</h3>
          <p className="mt-1 text-sm text-pmai-muted">
            ترجمه‌های خودکار، رشته‌های UI و برچسب‌ها از طریق WP-Cron یا دکمه زیر اجرا می‌شوند.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={handleProcessQueues}
            disabled={busy}
            className="inline-flex items-center gap-2 rounded-lg bg-pmai-primary px-4 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <HiArrowPath className={`h-4 w-4 ${processing ? 'animate-spin' : ''}`} />
            {processing ? 'در حال پردازش…' : 'پردازش صف‌ها'}
          </button>
          <button
            type="button"
            onClick={handleResyncVariations}
            disabled={busy}
            className="inline-flex items-center gap-2 rounded-lg border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-800 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <HiArrowPath className={`h-4 w-4 ${resyncing ? 'animate-spin' : ''}`} />
            {resyncing ? 'در حال هماهنگ‌سازی…' : 'هماهنگ‌سازی متغیرها'}
          </button>
        </div>
      </div>

      {notice && (
        <div className="mt-4">
          <Notice
            type={notice.type}
            message={notice.message}
            onDismiss={() => setNotice(null)}
          />
        </div>
      )}

      {cronDisabled && (
        <div className="mt-4 flex gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
          <HiExclamationTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
          <div>
            <p className="font-medium">WP-Cron غیرفعال است (DISABLE_WP_CRON)</p>
            <p className="mt-1 text-amber-900">
              ترجمه پس‌زمینه فقط با cron واقعی سرور یا دکمه «پردازش صف‌ها» اجرا می‌شود.
            </p>
            {cronUrl && (
              <code className="mt-2 block overflow-x-auto rounded border border-amber-200 bg-white px-2 py-1 text-xs" dir="ltr">
                */1 * * * * curl -s {cronUrl} &gt;/dev/null
              </code>
            )}
          </div>
        </div>
      )}

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <div className="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
          <p className="text-xs text-pmai-muted">رشته‌های UI در صف</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{queue?.pending_strings ?? 0}</p>
        </div>
        <div className="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
          <p className="text-xs text-pmai-muted">ترجمه محتوا (cron)</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{queue?.scheduled_posts ?? 0}</p>
        </div>
        <div className="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
          <p className="text-xs text-pmai-muted">ترجمه برچسب (cron)</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{queue?.scheduled_terms ?? 0}</p>
        </div>
      </div>

      <div className="mt-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-950">
        <p className="font-medium text-blue-900">راهنمای warm-up قبل از پروداکشن</p>
        <ol className="mt-2 list-inside list-decimal space-y-1 text-blue-900">
          <li>ترجمه گروهی محتوا و رشته‌های UI را کامل کنید.</li>
          <li>یک‌بار «هماهنگ‌سازی متغیرها» را بزنید تا meta عنوان سفارشی WVE با ساختار جدید هم‌تراز شود.</li>
          <li>چند بار «پردازش صف‌ها» را بزنید تا صف runtime خالی شود.</li>
          <li>صفحات مهم سایت را یک‌بار با پیشوند زبان (مثلاً /en/) باز کنید.</li>
        </ol>
        {!hasWork && (
          <p className="mt-2 text-green-800">✓ در حال حاضر صف پس‌زمینه خالی است.</p>
        )}
      </div>
    </div>
  );
}
