export default function Pagination({ page, pages, total, onPageChange, loading = false }) {
  if (pages <= 1) {
    return null;
  }

  return (
    <div className="mt-4 flex items-center justify-between rounded-lg border border-pmai-border bg-white px-4 py-3 text-sm">
      <span className="text-pmai-muted">
        صفحه {page} از {pages} — {total} مورد
      </span>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={page <= 1 || loading}
          onClick={() => onPageChange(page - 1)}
          className="rounded-lg border border-pmai-border px-4 py-1.5 font-medium transition hover:bg-gray-50 disabled:opacity-50"
        >
          قبلی
        </button>
        <button
          type="button"
          disabled={page >= pages || loading}
          onClick={() => onPageChange(page + 1)}
          className="rounded-lg border border-pmai-border px-4 py-1.5 font-medium transition hover:bg-gray-50 disabled:opacity-50"
        >
          بعدی
        </button>
      </div>
    </div>
  );
}
