import { useCallback, useEffect, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import Pagination from './components/ui/Pagination';
import { SkeletonTable } from './components/ui/LoadingSkeleton';
import { fetchReport } from './api/report';
import { getTargetLanguageOptions } from './hooks/useTargetLanguages';
import { fetchLanguages } from './api/languages';
import { HiArrowPath } from './components/ui/icons';

const PAGE_SIZE = 25;

const SOURCE_LABELS = {
  ai: 'هوش مصنوعی',
  auto: 'ترجمه خودکار',
  bulk: 'ترجمه گروهی',
  manual: 'دستی',
};

function formatDate(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('fa-IR');
}

function exportCsv(items) {
  const headers = ['زمان', 'نوع', 'زبان', 'عنوان فارسی', 'عنوان ترجمه', 'منبع'];
  const rows = items.map((row) => [
    formatDate(row.time),
    row.post_type_label,
    row.lang_label,
    row.title_source,
    row.title_translated,
    SOURCE_LABELS[row.source] ?? row.source,
  ]);

  const csv = [headers, ...rows]
    .map((line) => line.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
    .join('\n');

  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `polymart-translation-report-${Date.now()}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

export default function ReportApp() {
  const [items, setItems] = useState([]);
  const [langOptions, setLangOptions] = useState([]);
  const [lang, setLang] = useState('');
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchReport({
        limit: PAGE_SIZE,
        page,
        lang: lang || undefined,
      });
      setItems(data.items ?? []);
      setTotal(data.total ?? 0);
      setPages(data.pages ?? 1);
    } catch {
      setNotice({ type: 'error', message: 'بارگذاری گزارش ناموفق بود.' });
    } finally {
      setLoading(false);
    }
  }, [lang, page]);

  useEffect(() => {
    fetchLanguages()
      .then((data) => {
        setLangOptions(getTargetLanguageOptions(data.languages ?? []));
      })
      .catch(() => {
        setLangOptions(getTargetLanguageOptions([]));
      });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <Layout
      title="گزارش ترجمه"
      subtitle="تاریخچه ترجمه‌ها — چه محتوایی به چه زبانی ترجمه شده"
    >
      {notice && (
        <div className="mb-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-700">فیلتر زبان</label>
          <select
            value={lang}
            onChange={(e) => {
              setLang(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-pmai-border px-3 py-2 text-sm"
          >
            <option value="">همه زبان‌ها</option>
            {langOptions.map((l) => (
              <option key={l.code} value={l.code}>
                {l.native_name}
              </option>
            ))}
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
          خروجی CSV (صفحه جاری)
        </button>
      </div>

      <div className="overflow-hidden rounded-lg border border-pmai-border bg-white shadow-sm">
        {loading ? (
          <SkeletonTable rows={8} />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="border-b border-pmai-border bg-gray-50 text-right">
                <tr>
                  <th className="px-4 py-3 font-medium">زمان</th>
                  <th className="px-4 py-3 font-medium">نوع</th>
                  <th className="px-4 py-3 font-medium">زبان</th>
                  <th className="px-4 py-3 font-medium">عنوان فارسی</th>
                  <th className="px-4 py-3 font-medium">عنوان ترجمه</th>
                  <th className="px-4 py-3 font-medium">منبع</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-12 text-center text-pmai-muted">
                      هنوز گزارشی ثبت نشده.
                    </td>
                  </tr>
                ) : (
                  items.map((row) => (
                    <tr key={row.id} className="border-b border-gray-100 hover:bg-gray-50">
                      <td className="whitespace-nowrap px-4 py-3 text-pmai-muted">{formatDate(row.time)}</td>
                      <td className="px-4 py-3 text-pmai-muted">{row.post_type_label}</td>
                      <td className="px-4 py-3">
                        <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                          {row.lang_label}
                        </span>
                      </td>
                      <td className="max-w-[180px] truncate px-4 py-3 font-medium">{row.title_source}</td>
                      <td className="max-w-[180px] truncate px-4 py-3 text-pmai-muted" dir="ltr">
                        {row.title_translated}
                      </td>
                      <td className="px-4 py-3 text-pmai-muted">
                        {SOURCE_LABELS[row.source] ?? row.source}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Pagination page={page} pages={pages} total={total} loading={loading} onPageChange={setPage} />
    </Layout>
  );
}
