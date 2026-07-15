import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import StatCard from './components/ui/StatCard';
import Modal from './components/ui/Modal';
import { SkeletonCard, SkeletonTable } from './components/ui/LoadingSkeleton';
import TranslationEditor from './components/translation/TranslationEditor';
import { HiArrowPath, HiMagnifyingGlass, HiSparkles, HiPencilSquare } from './components/ui/icons';
import { fetchTranslation,
  fetchTranslations,
  generateTranslation,
  saveTranslation,
} from './api/translations';
import Pagination from './components/ui/Pagination';
import LanguageSelect from './components/ui/LanguageSelect';
import { useTargetLanguages } from './hooks/useTargetLanguages';

const STATUS_OPTIONS = [
  { value: 'all', label: 'همه' },
  { value: 'untranslated', label: 'ترجمه‌نشده' },
  { value: 'partial', label: 'ناقص' },
  { value: 'translated', label: 'ترجمه‌شده' },
];

const TYPE_OPTIONS = [
  { value: '', label: 'همه انواع' },
  { value: 'product', label: 'محصولات' },
  { value: 'post', label: 'نوشته‌ها' },
  { value: 'page', label: 'برگه‌ها' },
  { value: 'cms_block', label: 'بلوک‌های HTML' },
  { value: 'woodmart_layout', label: 'لایه‌های وودمارت' },
  { value: 'woodmart_slide', label: 'اسلایدهای وودمارت' },
];

function statusBadge(status) {
  if (status === 'translated') {
    return { label: 'ترجمه‌شده', className: 'bg-green-100 text-green-800' };
  }
  if (status === 'partial') {
    return { label: 'ناقص', className: 'bg-amber-100 text-amber-800' };
  }
  return { label: 'ترجمه‌نشده', className: 'bg-red-100 text-red-800' };
}

function formatDate(timestamp) {
  if (!timestamp) {
    return '—';
  }

  return new Date(timestamp * 1000).toLocaleDateString('fa-IR');
}

export default function TranslationsApp() {
  const {
    langOptions,
    targetLang,
    setTargetLang,
    loading: langsLoading,
    error: langsError,
    targetLabel: targetLangLabel,
  } = useTargetLanguages('en');

  const [status, setStatus] = useState('all');
  const [postType, setPostType] = useState('');
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [page, setPage] = useState(1);
  const [listData, setListData] = useState({ items: [], stats: {}, total: 0, pages: 1 });
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState(null);

  const [selectedId, setSelectedId] = useState(null);
  const [editorItem, setEditorItem] = useState(null);
  const [editorLoading, setEditorLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [generatingIds, setGeneratingIds] = useState(() => new Set());
  const editorSnapshotRef = useRef('');
  const editorRequestRef = useRef(0);

  const syncEditorSnapshot = useCallback((item) => {
    editorSnapshotRef.current = JSON.stringify(item ?? null);
  }, []);

  const isEditorDirty = useCallback(() => {
    if (!editorItem) {
      return false;
    }

    return JSON.stringify(editorItem) !== editorSnapshotRef.current;
  }, [editorItem]);

  const confirmCloseEditor = useCallback(() => {
    if (!isEditorDirty()) {
      return true;
    }

    return window.confirm('تغییرات ذخیره نشده‌اند. بدون ذخیره بسته شود؟');
  }, [isEditorDirty]);

  const loadList = useCallback(async ({ preserveNotice = false } = {}) => {
    setLoading(true);

    if (!preserveNotice) {
      setNotice(null);
    }

    try {
      const data = await fetchTranslations({
        status: status === 'all' ? '' : status,
        post_type: postType,
        search,
        page,
        lang: targetLang,
      });
      setListData(data);
    } catch {
      setNotice({ type: 'error', message: 'بارگذاری لیست ترجمه‌ها ناموفق بود.' });
    } finally {
      setLoading(false);
    }
  }, [status, postType, search, page, targetLang]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const openEditor = async (postId) => {
    if (selectedId && selectedId !== postId && !confirmCloseEditor()) {
      return;
    }

    const requestId = ++editorRequestRef.current;

    setSelectedId(postId);
    setEditorItem(null);
    setEditorLoading(true);
    setNotice(null);

    try {
      const item = await fetchTranslation(postId, targetLang);

      if (requestId !== editorRequestRef.current) {
        return;
      }

      setEditorItem(item);
      syncEditorSnapshot(item);
    } catch {
      if (requestId !== editorRequestRef.current) {
        return;
      }

      setNotice({ type: 'error', message: 'بارگذاری جزئیات ترجمه ناموفق بود.' });
      setSelectedId(null);
    } finally {
      if (requestId === editorRequestRef.current) {
        setEditorLoading(false);
      }
    }
  };

  const closeEditor = () => {
    editorRequestRef.current += 1;
    setSelectedId(null);
    setEditorItem(null);
    editorSnapshotRef.current = '';
  };

  const requestCloseEditor = () => {
    if (!confirmCloseEditor()) {
      return;
    }

    closeEditor();
  };

  const handleSave = async (fields) => {
    if (!selectedId) {
      return;
    }

    setSaving(true);
    setNotice(null);

    try {
      const result = await saveTranslation(selectedId, fields, targetLang);
      setEditorItem(result.item);
      syncEditorSnapshot(result.item);
      setNotice({ type: 'success', message: result.message });
      await loadList({ preserveNotice: true });
    } catch (error) {
      const message = error?.response?.data?.message || 'ذخیره ترجمه ناموفق بود.';
      setNotice({ type: 'error', message });
    } finally {
      setSaving(false);
    }
  };

  const setGenerating = (postId, active) => {
    setGeneratingIds((prev) => {
      const next = new Set(prev);
      if (active) {
        next.add(postId);
      } else {
        next.delete(postId);
      }
      return next;
    });
  };

  const handleGenerate = async (postId = selectedId) => {
    if (!postId) {
      return;
    }

    setGenerating(postId, true);
    setNotice(null);

    try {
      const result = await generateTranslation(postId, targetLang);
      if (selectedId === postId) {
        setEditorItem(result.item);
        syncEditorSnapshot(result.item);
      }
      setNotice({ type: 'success', message: result.message });
      await loadList({ preserveNotice: true });
    } catch (error) {
      const message = error?.response?.data?.message || 'تولید ترجمه ناموفق بود.';
      setNotice({ type: 'error', message });
    } finally {
      setGenerating(postId, false);
    }
  };

  const handleSearchSubmit = (event) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };

  const handleStatusFilter = (newStatus) => {
    setStatus(newStatus);
    setPage(1);
  };

  const { stats = {}, items = [], pages = 1, total = 0 } = listData;

  return (
    <Layout
      title="مدیریت ترجمه"
      subtitle={`ویرایش دستی یا تولید خودکار ترجمه ${targetLangLabel} محتوای فارسی`}
    >
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

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {loading && !stats.total ? (
          Array.from({ length: 4 }).map((_, i) => <SkeletonCard key={i} />)
        ) : (
          <>
            <StatCard label="کل موارد" value={stats.total ?? 0} />
            <StatCard
              label="ترجمه‌نشده"
              value={stats.untranslated ?? 0}
              color="text-red-700"
              active={status === 'untranslated'}
              onClick={() => handleStatusFilter('untranslated')}
            />
            <StatCard
              label="ناقص"
              value={stats.partial ?? 0}
              color="text-amber-700"
              active={status === 'partial'}
              onClick={() => handleStatusFilter('partial')}
            />
            <StatCard
              label="ترجمه‌شده"
              value={stats.translated ?? 0}
              color="text-green-700"
              active={status === 'translated'}
              onClick={() => handleStatusFilter('translated')}
            />
          </>
        )}
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-pmai-border bg-pmai-surface p-4 shadow-sm">
        <div>
          <label htmlFor="translations-target-lang" className="mb-1 block text-xs font-medium text-gray-700">
            زبان مقصد
          </label>
          <LanguageSelect
            id="translations-target-lang"
            value={targetLang}
            onChange={(code) => {
              setTargetLang(code);
              setPage(1);
              closeEditor();
            }}
            options={langOptions}
            loading={langsLoading}
          />
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-gray-700">وضعیت</label>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
          >
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-gray-700">نوع</label>
          <select
            value={postType}
            onChange={(e) => {
              setPostType(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
          >
            {TYPE_OPTIONS.map((option) => (
              <option key={option.value || 'all'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <form onSubmit={handleSearchSubmit} className="flex min-w-[220px] flex-1 gap-2">
          <input
            type="search"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="جستجو در عنوان…"
            className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
          />
          <button
            type="submit"
            className="inline-flex items-center gap-1 rounded-lg border border-pmai-border bg-white px-4 py-2 text-sm font-medium transition hover:bg-gray-50"
          >
            <HiMagnifyingGlass className="h-4 w-4" />
            جستجو
          </button>
        </form>

        <button
          type="button"
          onClick={loadList}
          disabled={loading}
          className="inline-flex items-center gap-1 rounded-lg border border-pmai-border bg-white px-4 py-2 text-sm font-medium transition hover:bg-gray-50 disabled:opacity-60"
        >
          <HiArrowPath className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          به‌روزرسانی
        </button>
      </div>

      <div className="overflow-hidden rounded-lg border border-pmai-border bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-pmai-border bg-gray-50 px-4 py-2 text-xs text-pmai-muted">
          <span>{loading ? 'در حال بارگذاری…' : `${total} مورد یافت شد`}</span>
          {status !== 'all' && (
            <button
              type="button"
              onClick={() => handleStatusFilter('all')}
              className="text-pmai-primary hover:underline"
            >
              پاک کردن فیلتر
            </button>
          )}
        </div>

        {loading ? (
          <SkeletonTable rows={6} />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="border-b border-pmai-border bg-gray-50 text-right">
                <tr>
                  <th className="px-4 py-3 font-medium text-gray-700">وضعیت</th>
                  <th className="px-4 py-3 font-medium text-gray-700">نوع</th>
                  <th className="px-4 py-3 font-medium text-gray-700">عنوان فارسی</th>
                  <th className="px-4 py-3 font-medium text-gray-700">عنوان {targetLangLabel}</th>
                  <th className="px-4 py-3 font-medium text-gray-700">آخرین ترجمه</th>
                  <th className="px-4 py-3 font-medium text-gray-700">عملیات</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-12 text-center text-pmai-muted">
                      <p className="text-base">موردی یافت نشد.</p>
                      <p className="mt-1 text-xs">فیلترها را تغییر دهید یا جستجوی دیگری انجام دهید.</p>
                    </td>
                  </tr>
                ) : (
                  items.map((item) => {
                    const badge = statusBadge(item.status);
                    const isSelected = selectedId === item.post_id;
                    const isGenerating = generatingIds.has(item.post_id);

                    return (
                      <tr
                        key={item.post_id}
                        className={`border-b border-gray-100 transition ${
                          isSelected ? 'bg-blue-50' : 'hover:bg-gray-50'
                        }`}
                      >
                        <td className="px-4 py-3">
                          <span
                            className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${badge.className}`}
                          >
                            {badge.label}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-pmai-muted">{item.post_type_label}</td>
                        <td className="max-w-[200px] truncate px-4 py-3 font-medium text-gray-900">
                          {item.title_fa}
                        </td>
                        <td className="max-w-[200px] truncate px-4 py-3 text-pmai-muted" dir="ltr">
                          {item.title_en || '—'}
                        </td>
                        <td className="px-4 py-3 text-pmai-muted">{formatDate(item.translated_at)}</td>
                        <td className="px-4 py-3">
                          <div className="flex flex-wrap gap-2">
                            <button
                              type="button"
                              onClick={() => openEditor(item.post_id)}
                              className="inline-flex items-center gap-1 rounded-lg border border-pmai-border px-3 py-1 text-xs font-medium transition hover:bg-gray-50"
                            >
                              <HiPencilSquare className="h-3.5 w-3.5" />
                              ویرایش
                            </button>
                            <button
                              type="button"
                              onClick={() => handleGenerate(item.post_id)}
                              disabled={isGenerating}
                              className="inline-flex items-center gap-1 rounded-lg bg-pmai-primary px-3 py-1 text-xs font-medium text-white transition hover:bg-pmai-primary-dark disabled:opacity-60"
                            >
                              <HiSparkles className="h-3.5 w-3.5" />
                              {isGenerating ? '…' : 'AI'}
                            </button>
                            {item.edit_url && (
                              <a
                                href={item.edit_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-lg border border-pmai-border px-3 py-1 text-xs text-gray-600 transition hover:bg-gray-50"
                              >
                                WP
                              </a>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Pagination page={page} pages={pages} total={total} loading={loading} onPageChange={setPage} />

      <Modal
        open={Boolean(selectedId)}
        title={editorItem?.title_fa || 'ویرایش ترجمه'}
        onClose={requestCloseEditor}
        confirmClose={confirmCloseEditor}
        wide
      >
        {editorLoading ? (
          <div className="py-12 text-center">
            <span className="inline-block h-6 w-6 animate-spin rounded-full border-2 border-pmai-primary border-t-transparent" />
            <p className="mt-2 text-pmai-muted">در حال بارگذاری ویرایشگر…</p>
          </div>
        ) : (
          editorItem && (
            <TranslationEditor
              item={editorItem}
              saving={saving}
              generating={generatingIds.has(selectedId)}
              onChange={setEditorItem}
              onSave={handleSave}
              onGenerate={() => handleGenerate()}
              onClose={requestCloseEditor}
              embedded
            />
          )
        )}
      </Modal>

      <aside className="mt-8 rounded-lg border border-blue-200 bg-gradient-to-l from-blue-50 to-white p-5 text-sm text-blue-950">
        <h3 className="text-base font-semibold text-blue-900">راهنمای مدیریت ترجمه</h3>
        <ul className="mt-3 list-inside list-disc space-y-2">
          <li>فقط محصولات و نوشته‌هایی که واقعاً متن فارسی دارند در این لیست نمایش داده می‌شوند.</li>
          <li>روی کارت‌های آماری کلیک کنید تا فیلتر وضعیت اعمال شود.</li>
          <li>ویرایشگر در پنجره modal باز می‌شود — فارسی و {targetLangLabel} کنار هم نمایش داده می‌شوند.</li>
          <li>دکمه AI در جدول، بدون باز کردن ویرایشگر مستقیم ترجمه می‌کند.</li>
        </ul>
      </aside>
    </Layout>
  );
}
