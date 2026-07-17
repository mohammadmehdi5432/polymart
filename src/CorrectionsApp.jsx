import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import LanguageSelect from './components/ui/LanguageSelect';
import { useTargetLanguages } from './hooks/useTargetLanguages';
import {
  applyCorrections,
  clearCorrectionsJob,
  deleteGlossaryEntry,
  fetchCorrectionsJob,
  fetchGlossary,
  previewCorrections,
  stepCorrectionsJob,
} from './api/corrections';
import { HiArrowPath, HiCheckCircle, HiMagnifyingGlass, HiTrash } from './components/ui/icons';

const SCOPE_OPTIONS = [
  {
    id: 'ui_strings',
    label: 'رشته‌های UI و کش ران‌تایم',
    hint: 'جستجو، دکمه‌ها، وودمارت، gettext',
  },
  {
    id: 'products',
    label: 'محصولات و برگه‌ها',
    hint: 'عنوان، توضیح، متا، ویژگی‌ها',
  },
  {
    id: 'elementor',
    label: 'Elementor',
    hint: 'صفحات و قالب‌های ترجمه‌شده',
  },
];

const SCOPE_LABELS = {
  ui_strings: 'UI',
  products: 'محصول/برگه',
  elementor: 'Elementor',
};

function errorMessage(error, fallback) {
  return error?.response?.data?.message || error?.message || fallback;
}

export default function CorrectionsApp() {
  const { langOptions, targetLang, setTargetLang, loading: langsLoading } = useTargetLanguages('ar');
  const [find, setFind] = useState('');
  const [replace, setReplace] = useState('');
  const [mode, setMode] = useState('contains');
  const [wordBoundary, setWordBoundary] = useState(false);
  const [scopes, setScopes] = useState(['ui_strings', 'products', 'elementor']);
  const [saveGlossary, setSaveGlossary] = useState(true);
  const [matches, setMatches] = useState([]);
  const [selected, setSelected] = useState({});
  const [replaceOverrides, setReplaceOverrides] = useState({});
  const [truncated, setTruncated] = useState(false);
  const [cursor, setCursor] = useState({});
  const [previewing, setPreviewing] = useState(false);
  const [applying, setApplying] = useState(false);
  const [job, setJob] = useState(null);
  const [glossaryEntries, setGlossaryEntries] = useState([]);
  const [notice, setNotice] = useState(null);
  const [section, setSection] = useState('correct');

  const selectedMatches = useMemo(
    () =>
      matches
        .filter((m) => selected[m.id])
        .map((m) => ({
          ...m,
          replace:
            typeof replaceOverrides[m.id] === 'string' && replaceOverrides[m.id].trim() !== ''
              ? replaceOverrides[m.id]
              : replace,
        })),
    [matches, selected, replaceOverrides, replace]
  );

  const loadGlossary = useCallback(async (lang) => {
    try {
      const data = await fetchGlossary(lang);
      setGlossaryEntries(data.entries ?? []);
    } catch {
      setGlossaryEntries([]);
    }
  }, []);

  useEffect(() => {
    if (targetLang) {
      loadGlossary(targetLang);
    }
  }, [targetLang, loadGlossary]);

  useEffect(() => {
    let active = true;

    fetchCorrectionsJob()
      .then((data) => {
        if (active && data?.status && data.status !== 'idle') {
          setJob(data);
        }
      })
      .catch(() => {});

    return () => {
      active = false;
    };
  }, []);

  const toggleScope = (id) => {
    setScopes((prev) =>
      prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id]
    );
  };

  const toggleMatch = (id) => {
    setSelected((prev) => ({ ...prev, [id]: !prev[id] }));
  };

	const selectAllMatches = (checked) => {
    const next = {};
    matches.forEach((m) => {
      next[m.id] = checked;
    });
    setSelected(next);
  };

  const matchReplaceValue = (matchId) => {
    if (typeof replaceOverrides[matchId] === 'string') {
      return replaceOverrides[matchId];
    }
    return replace;
  };

  const setMatchReplace = (matchId, value) => {
    setReplaceOverrides((prev) => ({ ...prev, [matchId]: value }));
  };

  const runPreview = async (append = false) => {
    if (!scopes.length) {
      setNotice({ type: 'error', message: 'حداقل یک محدوده را انتخاب کنید.' });
      return;
    }

    setPreviewing(true);
    setNotice(null);

    try {
      const data = await previewCorrections({
        lang: targetLang,
        find,
        replace,
        mode,
        word_boundary: wordBoundary,
        scopes,
        cursor: append ? cursor : {},
        limit: 50,
      });

      const nextMatches = data.matches ?? [];
      setMatches((prev) => (append ? [...prev, ...nextMatches] : nextMatches));
      setTruncated(Boolean(data.truncated));
      setCursor(data.next_cursor ?? {});

      if (!append) {
        const nextSelected = {};
        const nextOverrides = {};
        nextMatches.forEach((m) => {
          nextSelected[m.id] = true;
          nextOverrides[m.id] = replace;
        });
        setSelected(nextSelected);
        setReplaceOverrides(nextOverrides);
      } else {
        setSelected((prev) => {
          const next = { ...prev };
          nextMatches.forEach((m) => {
            next[m.id] = true;
          });
          return next;
        });
        setReplaceOverrides((prev) => {
          const next = { ...prev };
          nextMatches.forEach((m) => {
            if (typeof next[m.id] !== 'string') {
              next[m.id] = replace;
            }
          });
          return next;
        });
      }

      setNotice({
        type: nextMatches.length ? 'success' : data.truncated ? 'warning' : 'info',
        message: nextMatches.length
          ? `${nextMatches.length} مورد پیدا شد${data.truncated ? ' (نتایج محدود — می‌توانید بیشتر بارگذاری کنید)' : ''}. متن جایگزین هر مورد را می‌توانید پایین کارت ویرایش کنید.`
          : data.truncated
            ? 'در این دسته چیزی نبود. «بارگذاری بیشتر» را بزنید یا عبارت کوتاه‌تری مثل «دیکور کی ان دی» را امتحان کنید.'
            : 'موردی یافت نشد. برای placeholder جستجو، Elementor را تیک بزنید و حالت «شامل» را با بخشی از متن امتحان کنید.',
      });
    } catch (error) {
      setNotice({ type: 'error', message: errorMessage(error, 'پیش‌نمایش ناموفق بود.') });
    } finally {
      setPreviewing(false);
    }
  };

  const runApplySteps = async (initialJob) => {
    let current = initialJob;
    setJob(current);

    while (current?.status === 'running') {
      // eslint-disable-next-line no-await-in-loop
      current = await stepCorrectionsJob(15);
      setJob(current);
    }

    return current;
  };

  const runApply = async () => {
    if (!selectedMatches.length) {
      setNotice({ type: 'error', message: 'حداقل یک مورد را برای اعمال انتخاب کنید.' });
      return;
    }

    setApplying(true);
    setNotice(null);

    try {
      const started = await applyCorrections({
        lang: targetLang,
        find,
        replace,
        mode,
        word_boundary: wordBoundary,
        save_glossary: saveGlossary,
        matches: selectedMatches,
      });

      const finished = await runApplySteps(started);

      setNotice({
        type: finished?.status === 'completed' ? 'success' : 'warning',
        message:
          finished?.status === 'completed'
            ? `اعمال شد — ${finished.done ?? 0} مورد، ${finished.replacements ?? 0} جایگزینی.`
            : 'اعمال ناقص ماند؛ وضعیت جاب را بررسی کنید.',
      });

      await loadGlossary(targetLang);
      await runPreview(false);
    } catch (error) {
      setNotice({ type: 'error', message: errorMessage(error, 'اعمال تصحیح ناموفق بود.') });
    } finally {
      setApplying(false);
    }
  };

  const handleClearJob = async () => {
    try {
      const data = await clearCorrectionsJob();
      setJob(data);
      setNotice({ type: 'info', message: 'جاب تصحیح پاک شد.' });
    } catch (error) {
      setNotice({ type: 'error', message: errorMessage(error, 'پاک کردن جاب ناموفق بود.') });
    }
  };

  const handleDeleteGlossary = async (wrong) => {
    try {
      const data = await deleteGlossaryEntry({ lang: targetLang, wrong });
      setGlossaryEntries(data.entries ?? []);
      setNotice({ type: 'success', message: 'از واژه‌نامه حذف شد.' });
    } catch (error) {
      setNotice({ type: 'error', message: errorMessage(error, 'حذف از واژه‌نامه ناموفق بود.') });
    }
  };

  return (
    <Layout
      title="تصحیح ترجمه"
      subtitle="جایگزینی متن اشتباه در ترجمه‌های ذخیره‌شده و ثبت در واژه‌نامه برای جلوگیری از تکرار"
    >
      {notice && (
        <div className="mb-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      <div className="mb-6 flex gap-2 border-b border-pmai-border">
        <button
          type="button"
          onClick={() => setSection('correct')}
          className={`border-b-2 px-4 py-2 text-sm font-medium transition ${
            section === 'correct'
              ? 'border-pmai-primary text-pmai-primary'
              : 'border-transparent text-pmai-muted hover:text-gray-800'
          }`}
        >
          پیدا و جایگزین
        </button>
        <button
          type="button"
          onClick={() => setSection('glossary')}
          className={`border-b-2 px-4 py-2 text-sm font-medium transition ${
            section === 'glossary'
              ? 'border-pmai-primary text-pmai-primary'
              : 'border-transparent text-pmai-muted hover:text-gray-800'
          }`}
        >
          واژه‌نامه
        </button>
      </div>

      {section === 'correct' && (
        <div className="space-y-6">
          <section className="rounded-xl border border-pmai-border bg-white p-5 shadow-sm">
            <div className="grid gap-4 md:grid-cols-2">
              <label className="block text-sm">
                <span className="mb-1.5 block font-medium text-gray-800">زبان مقصد</span>
                <LanguageSelect
                  value={targetLang}
                  onChange={setTargetLang}
                  options={langOptions}
                  loading={langsLoading}
                  className="w-full"
                />
              </label>

              <label className="block text-sm">
                <span className="mb-1.5 block font-medium text-gray-800">حالت تطبیق</span>
                <select
                  value={mode}
                  onChange={(e) => setMode(e.target.value)}
                  className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
                >
                  <option value="contains">شامل (برای عبارت داخل متن)</option>
                  <option value="exact">دقیق (کل مقدار)</option>
                </select>
              </label>

              <label className="block text-sm md:col-span-2">
                <span className="mb-1.5 block font-medium text-gray-800">متن اشتباه</span>
                <textarea
                  value={find}
                  onChange={(e) => setFind(e.target.value)}
                  rows={2}
                  dir="auto"
                  placeholder="مثلاً ki and decor یا دیکور کی ان دی"
                  className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
                />
              </label>

              <label className="block text-sm md:col-span-2">
                <span className="mb-1.5 block font-medium text-gray-800">متن صحیح</span>
                <textarea
                  value={replace}
                  onChange={(e) => setReplace(e.target.value)}
                  rows={2}
                  dir="auto"
                  placeholder="مثلاً KND Decor یا کی ان دی دکور"
                  className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary"
                />
              </label>
            </div>

            <div className="mt-4">
              <p className="mb-2 text-sm font-medium text-gray-800">محدوده جستجو</p>
              <div className="grid gap-2 sm:grid-cols-3">
                {SCOPE_OPTIONS.map((scope) => (
                  <label
                    key={scope.id}
                    className={`flex cursor-pointer gap-3 rounded-lg border px-3 py-3 transition ${
                      scopes.includes(scope.id)
                        ? 'border-pmai-primary bg-pmai-primary/5'
                        : 'border-pmai-border bg-white hover:border-gray-300'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={scopes.includes(scope.id)}
                      onChange={() => toggleScope(scope.id)}
                      className="mt-1"
                    />
                    <span>
                      <span className="block text-sm font-medium text-gray-900">{scope.label}</span>
                      <span className="mt-0.5 block text-xs text-pmai-muted">{scope.hint}</span>
                    </span>
                  </label>
                ))}
              </div>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-4 text-sm">
              <label className="inline-flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={wordBoundary}
                  onChange={(e) => setWordBoundary(e.target.checked)}
                />
                مرز کلمه برای برند لاتین
              </label>
              <label className="inline-flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={saveGlossary}
                  onChange={(e) => setSaveGlossary(e.target.checked)}
                />
                ذخیره در واژه‌نامه (برای AI و استورفرانت)
              </label>
            </div>

            <div className="mt-5 flex flex-wrap gap-2">
              <button
                type="button"
                disabled={previewing || applying || !find.trim()}
                onClick={() => runPreview(false)}
                className="inline-flex items-center gap-2 rounded-lg bg-pmai-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:opacity-95 disabled:opacity-50"
              >
                <HiMagnifyingGlass className="h-4 w-4" />
                {previewing ? 'در حال جستجو…' : 'پیش‌نمایش'}
              </button>

              {truncated && Object.keys(cursor).length > 0 && (
                <button
                  type="button"
                  disabled={previewing || applying}
                  onClick={() => runPreview(true)}
                  className="inline-flex items-center gap-2 rounded-lg border border-pmai-border bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 disabled:opacity-50"
                >
                  بارگذاری بیشتر
                </button>
              )}

              <button
                type="button"
                disabled={applying || previewing || !selectedMatches.length}
                onClick={runApply}
                className="inline-flex items-center gap-2 rounded-lg border border-emerald-600 bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
              >
                <HiCheckCircle className="h-4 w-4" />
                {applying ? 'در حال اعمال…' : `اعمال روی ${selectedMatches.length} مورد`}
              </button>
            </div>
          </section>

          {job && job.status !== 'idle' && (
            <section className="rounded-xl border border-pmai-border bg-white p-4 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    وضعیت جاب: {job.status} — {job.progress ?? 0}%
                  </p>
                  <p className="mt-1 text-xs text-pmai-muted">
                    {job.done ?? 0}/{job.total ?? 0} مورد · {job.replacements ?? 0} جایگزینی
                  </p>
                </div>
                <div className="flex gap-2">
                  {job.status === 'running' && (
                    <button
                      type="button"
                      disabled={applying}
                      onClick={async () => {
                        setApplying(true);
                        try {
                          await runApplySteps(job);
                        } finally {
                          setApplying(false);
                        }
                      }}
                      className="inline-flex items-center gap-1 rounded-lg border border-pmai-border px-3 py-1.5 text-xs font-medium"
                    >
                      <HiArrowPath className="h-3.5 w-3.5" />
                      ادامه
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={handleClearJob}
                    className="rounded-lg border border-pmai-border px-3 py-1.5 text-xs font-medium text-pmai-muted"
                  >
                    پاک کردن جاب
                  </button>
                </div>
              </div>
              <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                <div
                  className="h-full rounded-full bg-pmai-primary transition-all"
                  style={{ width: `${Math.min(100, job.progress ?? 0)}%` }}
                />
              </div>
              {!!job.errors?.length && (
                <ul className="mt-3 space-y-1 text-xs text-red-600">
                  {job.errors.slice(-5).map((err) => (
                    <li key={`${err.id}-${err.message}`}>{err.message}</li>
                  ))}
                </ul>
              )}
            </section>
          )}

          {matches.length > 0 && (
            <section className="rounded-xl border border-pmai-border bg-white shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-pmai-border px-4 py-3">
                <label className="inline-flex items-center gap-2 text-sm font-medium">
                  <input
                    type="checkbox"
                    checked={matches.length > 0 && matches.every((m) => selected[m.id])}
                    onChange={(e) => selectAllMatches(e.target.checked)}
                  />
                  انتخاب همه ({matches.length})
                </label>
                {truncated && (
                  <span className="text-xs text-amber-700">نتایج محدود شده‌اند — در صورت نیاز «بارگذاری بیشتر» بزنید.</span>
                )}
              </div>

              <ul className="divide-y divide-pmai-border">
                {matches.map((match) => (
                  <li key={match.id} className="flex gap-3 px-4 py-3">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={Boolean(selected[match.id])}
                      onChange={() => toggleMatch(match.id)}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700">
                          {SCOPE_LABELS[match.scope] || match.scope}
                        </span>
                        <span className="truncate text-sm font-medium text-gray-900">
                          {match.label || match.id}
                        </span>
                        {match.edit_url && (
                          <a
                            href={match.edit_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-xs text-pmai-primary hover:underline"
                          >
                            ویرایش
                          </a>
                        )}
                      </div>
                      <p className="mt-1 text-xs text-pmai-muted">{match.location}</p>
                      <p className="mt-2 rounded-md bg-gray-50 px-2.5 py-2 text-sm leading-relaxed" dir="auto">
                        {match.snippet || match.value}
                      </p>
                      <label className="mt-3 block text-xs font-medium text-gray-700">
                        متن جایگزین این مورد
                        <textarea
                          value={matchReplaceValue(match.id)}
                          onChange={(e) => setMatchReplace(match.id, e.target.value)}
                          rows={2}
                          dir="auto"
                          disabled={!selected[match.id]}
                          className="mt-1 w-full rounded-lg border border-pmai-border px-2.5 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary disabled:bg-gray-50 disabled:opacity-60"
                        />
                      </label>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}

      {section === 'glossary' && (
        <section className="rounded-xl border border-pmai-border bg-white p-5 shadow-sm">
          <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold text-gray-900">واژه‌نامه ترجیحی</h2>
              <p className="mt-1 text-sm text-pmai-muted">
                این جفت‌ها روی خروجی استورفرانت اعمال می‌شوند و به پرامپت AI هم اضافه می‌گردند.
              </p>
            </div>
            <LanguageSelect
              value={targetLang}
              onChange={setTargetLang}
              options={langOptions}
              loading={langsLoading}
            />
          </div>

          {!glossaryEntries.length ? (
            <p className="text-sm text-pmai-muted">هنوز موردی برای این زبان ثبت نشده است.</p>
          ) : (
            <ul className="divide-y divide-pmai-border rounded-lg border border-pmai-border">
              {glossaryEntries.map((entry) => (
                <li
                  key={`${entry.wrong}-${entry.preferred}`}
                  className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                >
                  <div className="min-w-0 flex-1">
                    <p className="text-sm" dir="auto">
                      <span className="text-red-600 line-through">{entry.wrong}</span>
                      <span className="mx-2 text-pmai-muted">→</span>
                      <span className="font-medium text-emerald-700">{entry.preferred}</span>
                    </p>
                    <p className="mt-1 text-xs text-pmai-muted">
                      {entry.match === 'contains' ? 'شامل' : 'دقیق'}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => handleDeleteGlossary(entry.wrong)}
                    className="inline-flex items-center gap-1 rounded-md border border-pmai-border px-2.5 py-1.5 text-xs text-pmai-muted hover:border-red-300 hover:text-red-600"
                  >
                    <HiTrash className="h-3.5 w-3.5" />
                    حذف
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </Layout>
  );
}
