import { useCallback, useEffect, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import Pagination from './components/ui/Pagination';
import { SkeletonTable } from './components/ui/LoadingSkeleton';
import { clearLogs, fetchLogs } from './api/logs';
import { HiArrowPath, HiTrash } from './components/ui/icons';

const PAGE_SIZE = 25;

const LEVEL_STYLES = {
  error: 'bg-red-100 text-red-800',
  warning: 'bg-amber-100 text-amber-900',
  success: 'bg-green-100 text-green-800',
  info: 'bg-blue-100 text-blue-800',
};

const LEVEL_LABELS = {
  error: 'خطا',
  warning: 'هشدار',
  success: 'موفق',
  info: 'اطلاع',
};

function formatDate(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('fa-IR');
}

function exportCsv(items) {
  const headers = ['سطح', 'پیام', 'زمان'];
  const rows = items.map((entry) => [
    LEVEL_LABELS[entry.level] ?? entry.level,
    entry.message,
    formatDate(entry.time),
  ]);

  const csv = [headers, ...rows]
    .map((line) => line.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
    .join('\n');

  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `polymart-logs-${Date.now()}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

export default function LogsApp() {
  const [items, setItems] = useState([]);
  const [level, setLevel] = useState('');
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [clearing, setClearing] = useState(false);
  const [notice, setNotice] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchLogs({
        limit: PAGE_SIZE,
        page,
        level: level || undefined,
      });
      setItems(data.items ?? []);
      setTotal(data.total ?? 0);
      setPages(data.pages ?? 1);
    } catch {
      setNotice({ type: 'error', message: 'بارگذاری لاگ‌ها ناموفق بود.' });
    } finally {
      setLoading(false);
    }
  }, [level, page]);

  useEffect(() => {
    load();
  }, [load]);

  const handleClear = async () => {
    if (!window.confirm('همه لاگ‌ها پاک شوند؟')) return;
    setClearing(true);
    try {
      await clearLogs();
      setItems([]);
      setTotal(0);
      setPages(1);
      setPage(1);
      setNotice({ type: 'success', message: 'لاگ‌ها پاک شدند.' });
    } catch {
      setNotice({ type: 'error', message: 'پاک کردن لاگ‌ها ناموفق بود.' });
    } finally {
      setClearing(false);
    }
  };

  return (
    <Layout
      title="لاگ‌ها"
      subtitle="رویدادها، خطاها و هشدارهای سیستم ترجمه — خطاهای جدی نوتیف وردپرس هم می‌دهند"
    >
      {notice && (
        <div className="mb-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-700">سطح</label>
          <select
            value={level}
            onChange={(e) => {
              setLevel(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-pmai-border px-3 py-2 text-sm"
          >
            <option value="">همه</option>
            <option value="error">خطا</option>
            <option value="warning">هشدار</option>
            <option value="success">موفق</option>
            <option value="info">اطلاع</option>
          </select>
        </div>
        <button
          type="button"
          onClick={load}
          disabled={loading}
          className="inline-flex items-center gap-1 rounded-lg border border-pmai-border px-4 py-2 text-sm hover:bg-gray-50"
        >
          <HiArrowPath className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          به‌روزرسانی
        </button>
        <button
          type="button"
          onClick={() => exportCsv(items)}
          disabled={loading || items.length === 0}
          className="rounded-lg border border-pmai-border px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-50"
        >
          خروجی CSV
        </button>
        <button
          type="button"
          onClick={handleClear}
          disabled={clearing || total === 0}
          className="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:opacity-50"
        >
          <HiTrash className="h-4 w-4" />
          پاک کردن لاگ‌ها
        </button>
      </div>

      <div className="overflow-hidden rounded-lg border border-pmai-border bg-white shadow-sm">
        {loading ? (
          <SkeletonTable rows={10} />
        ) : (
          <div className="divide-y divide-gray-100">
            {items.length === 0 ? (
              <p className="px-4 py-12 text-center text-pmai-muted">لاگی یافت نشد.</p>
            ) : (
              items.map((entry) => (
                <div key={entry.id} className="flex flex-wrap items-start gap-3 px-4 py-3 hover:bg-gray-50">
                  <span
                    className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                      LEVEL_STYLES[entry.level] ?? LEVEL_STYLES.info
                    }`}
                  >
                    {LEVEL_LABELS[entry.level] ?? entry.level}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-gray-900">{entry.message}</p>
                    <p className="mt-0.5 text-xs text-pmai-muted">{formatDate(entry.time)}</p>
                  </div>
                </div>
              ))
            )}
          </div>
        )}
      </div>

      <Pagination page={page} pages={pages} total={total} loading={loading} onPageChange={setPage} />

      <aside className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
        <h3 className="font-semibold">درباره نوتیف وردپرس</h3>
        <p className="mt-2">
          خطاهای جدی مثل تمام شدن توکن API، مشکل احراز هویت یا قطع سرویس، علاوه بر ثبت در اینجا،
          یک اعلان در پیشخوان وردپرس هم نمایش می‌دهند.
        </p>
      </aside>
    </Layout>
  );
}
