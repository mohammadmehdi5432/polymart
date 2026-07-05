import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from './components/Layout';
import Notice from './components/ui/Notice';
import MediaPicker from './components/ui/MediaPicker';
import { SkeletonFields } from './components/ui/LoadingSkeleton';
import { HiPlus, HiCheckCircle } from './components/ui/icons';
import { fetchLanguages, saveLanguages } from './api/languages';
import { useUnsavedWarning } from './hooks/useUnsavedWarning';

function Toggle({ checked, onChange, label, disabled = false }) {
  return (
    <label
      className={`inline-flex items-center gap-2 ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}
    >
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded border-gray-300 text-pmai-primary focus:ring-pmai-primary"
      />
      <span className="text-sm text-gray-700">{label}</span>
    </label>
  );
}

export default function LanguagesApp() {
  const [languages, setLanguages] = useState([]);
  const [presets, setPresets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);
  const [showPresets, setShowPresets] = useState(false);
  const [savedLanguages, setSavedLanguages] = useState([]);

  const isDirty = useMemo(
    () => !loading && JSON.stringify(languages) !== JSON.stringify(savedLanguages),
    [loading, languages, savedLanguages]
  );

  useUnsavedWarning(isDirty);

  const load = useCallback(async () => {
    setLoading(true);

    try {
      const data = await fetchLanguages();
      const normalizeLanguage = (lang) => ({
        ...lang,
        flag_attachment_id: Number(lang.flag_attachment_id) || 0,
        flag_url: lang.flag_url || '',
        cpc_currency_icon_attachment_id: Number(lang.cpc_currency_icon_attachment_id) || 0,
        cpc_currency_icon_url: lang.cpc_currency_icon_url || '',
        product_placeholder_attachment_id: Number(lang.product_placeholder_attachment_id) || 0,
        product_placeholder_url: lang.product_placeholder_url || '',
      });
      setLanguages((data.languages ?? []).map(normalizeLanguage));
      setSavedLanguages((data.languages ?? []).map(normalizeLanguage));
      setPresets(data.presets ?? []);
    } catch {
      setNotice({ type: 'error', message: 'بارگذاری زبان‌ها ناموفق بود.' });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const updateLanguage = (index, field, value) => {
    setLanguages((prev) =>
      prev.map((lang, i) => {
        if (i !== index) {
          if (field === 'is_default' && value) {
            return { ...lang, is_default: false };
          }
          return lang;
        }

        const updated = { ...lang, [field]: value };

        if (field === 'is_default' && value) {
          updated.url_prefix = '';
          updated.enabled = true;
        }

        if (field === 'code' && !lang.is_default) {
          updated.url_prefix = value;
        }

        return updated;
      })
    );
  };

  const addPreset = (preset) => {
    if (languages.some((lang) => lang.code === preset.code)) {
      setNotice({ type: 'warning', message: 'این زبان قبلاً اضافه شده است.' });
      return;
    }

    setLanguages((prev) => [
      ...prev,
      {
        ...preset,
        enabled: true,
        flag_attachment_id: 0,
        flag_url: '',
        cpc_currency_icon_attachment_id: 0,
        cpc_currency_icon_url: '',
        product_placeholder_attachment_id: 0,
        product_placeholder_url: '',
      },
    ]);
    setShowPresets(false);
  };

  const removeLanguage = (index) => {
    const lang = languages[index];

    if (lang?.is_default) {
      setNotice({ type: 'warning', message: 'زبان پیش‌فرض قابل حذف نیست.' });
      return;
    }

    setLanguages((prev) => prev.filter((_, i) => i !== index));
  };

  const handleFlagChange = (index, attachmentId, url) => {
    setLanguages((prev) =>
      prev.map((lang, i) =>
        i === index
          ? { ...lang, flag_attachment_id: attachmentId, flag_url: url }
          : lang
      )
    );
  };

  const handleCpcCurrencyIconChange = (index, attachmentId, url) => {
    setLanguages((prev) =>
      prev.map((lang, i) =>
        i === index
          ? { ...lang, cpc_currency_icon_attachment_id: attachmentId, cpc_currency_icon_url: url }
          : lang
      )
    );
  };

  const handleProductPlaceholderChange = (index, attachmentId, url) => {
    setLanguages((prev) =>
      prev.map((lang, i) =>
        i === index
          ? { ...lang, product_placeholder_attachment_id: attachmentId, product_placeholder_url: url }
          : lang
      )
    );
  };

  const handleSave = async () => {
    setSaving(true);
    setNotice(null);

    try {
      const payload = languages.map(({ flag_url, cpc_currency_icon_url, product_placeholder_url, url, ...lang }) => lang);
      const result = await saveLanguages(payload);
      const nextLanguages = result.languages ?? payload;
      setLanguages(nextLanguages);
      setSavedLanguages(nextLanguages);
      setNotice({ type: 'success', message: result.message || 'زبان‌ها ذخیره شدند.' });
    } catch (error) {
      const message = error?.response?.data?.message || 'ذخیره زبان‌ها ناموفق بود.';
      setNotice({ type: 'error', message });
    } finally {
      setSaving(false);
    }
  };

  const availablePresets = presets.filter(
    (preset) => !languages.some((lang) => lang.code === preset.code)
  );

  return (
    <Layout
      title="مدیریت زبان‌ها"
      subtitle="زبان پیش‌فرض فارسی است — زبان‌های جدید با پرچم و پیشوند URL اضافه کنید"
    >
      {isDirty && (
        <div className="mb-4">
          <Notice type="warning" message="تغییرات ذخیره نشده‌اند — قبل از خروج «ذخیره زبان‌ها» را بزنید." />
        </div>
      )}

      {notice && (
        <div className="mb-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      <div className="mb-4">
        <button
          type="button"
          onClick={() => setShowPresets((prev) => !prev)}
          className="inline-flex items-center gap-2 rounded-lg border border-pmai-primary bg-pmai-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-pmai-primary-dark"
        >
          <HiPlus className="h-4 w-4" />
          افزودن زبان
        </button>
      </div>

      {showPresets && (
        <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
          <p className="mb-3 text-sm font-medium text-blue-900">انتخاب زبان</p>
          {availablePresets.length === 0 ? (
            <p className="text-sm text-blue-800">همه زبان‌های پیشنهادی قبلاً اضافه شده‌اند.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {availablePresets.map((preset) => (
                <button
                  key={preset.code}
                  type="button"
                  onClick={() => addPreset(preset)}
                  className="rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm transition hover:bg-blue-100"
                >
                  <span className="font-medium">{preset.native_name}</span>
                  <span className="mr-2 text-pmai-muted">({preset.name})</span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      {loading ? (
        <SkeletonFields count={4} />
      ) : (
        <div className="space-y-4">
          {languages.map((lang, index) => (
            <div
              key={`${lang.code}-${index}`}
              className={`rounded-lg border bg-white p-5 shadow-sm ${
                lang.is_default ? 'border-pmai-primary ring-1 ring-pmai-primary/20' : 'border-pmai-border'
              }`}
            >
              <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-2">
                  {lang.is_default && (
                    <span className="rounded-full bg-pmai-primary px-2.5 py-0.5 text-xs font-medium text-white">
                      پیش‌فرض
                    </span>
                  )}
                  <h3 className="text-base font-semibold text-gray-900">
                    {lang.native_name || lang.name}
                  </h3>
                  <code className="rounded bg-gray-100 px-2 py-0.5 text-xs" dir="ltr">
                    {lang.code}
                  </code>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                  <Toggle
                    checked={lang.enabled}
                    onChange={(value) => updateLanguage(index, 'enabled', value)}
                    label="فعال"
                    disabled={lang.is_default}
                  />
                  {!lang.is_default && (
                    <button
                      type="button"
                      onClick={() => removeLanguage(index)}
                      className="text-sm text-red-600 hover:underline"
                    >
                      حذف
                    </button>
                  )}
                </div>
              </div>

              <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-4">
                  <MediaPicker
                    attachmentId={lang.flag_attachment_id}
                    imageUrl={lang.flag_url}
                    onChange={(id, url) => handleFlagChange(index, id, url)}
                    label={`پرچم ${lang.native_name}`}
                  />
                  <MediaPicker
                    attachmentId={lang.cpc_currency_icon_attachment_id}
                    imageUrl={lang.cpc_currency_icon_url}
                    onChange={(id, url) => handleCpcCurrencyIconChange(index, id, url)}
                    label={`علامت قیمت کارت محصول (CPC) — ${lang.native_name}`}
                  />
                  <MediaPicker
                    attachmentId={lang.product_placeholder_attachment_id}
                    imageUrl={lang.product_placeholder_url}
                    onChange={(id, url) => handleProductPlaceholderChange(index, id, url)}
                    label={`تصویر جایگزین محصول بدون عکس — ${lang.native_name}`}
                  />
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-700">نام</label>
                    <input
                      type="text"
                      value={lang.name}
                      onChange={(e) => updateLanguage(index, 'name', e.target.value)}
                      className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm"
                    />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-700">نام بومی</label>
                    <input
                      type="text"
                      value={lang.native_name}
                      onChange={(e) => updateLanguage(index, 'native_name', e.target.value)}
                      className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm"
                    />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-700">کد زبان</label>
                    <input
                      type="text"
                      value={lang.code}
                      onChange={(e) => updateLanguage(index, 'code', e.target.value.toLowerCase())}
                      disabled={lang.is_default}
                      className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm disabled:bg-gray-50"
                      dir="ltr"
                    />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-700">پیشوند URL</label>
                    <input
                      type="text"
                      value={lang.url_prefix}
                      onChange={(e) => updateLanguage(index, 'url_prefix', e.target.value.toLowerCase())}
                      disabled={lang.is_default}
                      placeholder="en, ar, zh ..."
                      className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm disabled:bg-gray-50"
                      dir="ltr"
                    />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-700">جهت متن</label>
                    <select
                      value={lang.direction}
                      onChange={(e) => updateLanguage(index, 'direction', e.target.value)}
                      disabled={lang.is_default}
                      className="w-full rounded-lg border border-pmai-border px-3 py-2 text-sm disabled:bg-gray-50"
                    >
                      <option value="rtl">راست به چپ (RTL)</option>
                      <option value="ltr">چپ به راست (LTR)</option>
                    </select>
                  </div>
                  {lang.url && (
                    <div>
                      <label className="mb-1 block text-xs font-medium text-gray-700">آدرس نمونه</label>
                      <code className="block truncate rounded-lg bg-gray-50 px-3 py-2 text-xs" dir="ltr">
                        {lang.url}
                      </code>
                    </div>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="mt-8 flex items-center gap-3 border-t border-pmai-border pt-6">
        <button
          type="button"
          onClick={handleSave}
          disabled={loading || saving || !isDirty}
          className="inline-flex items-center gap-2 rounded-lg bg-pmai-primary px-5 py-2.5 font-medium text-white shadow-sm transition hover:bg-pmai-primary-dark disabled:opacity-60"
        >
          <HiCheckCircle className="h-5 w-5" />
          {saving ? 'در حال ذخیره…' : 'ذخیره زبان‌ها'}
        </button>
      </div>

      <aside className="mt-8 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950">
        <h3 className="text-base font-semibold text-blue-900">راهنمای زبان‌ها</h3>
        <ul className="mt-3 list-inside list-disc space-y-2">
          <li>زبان پیش‌فرض (فارسی) منبع ترجمه است و پیشوند URL ندارد.</li>
          <li>هر زبان دیگر با پیشوند URL مثل <code dir="ltr">/en/</code> یا <code dir="ltr">/ar/</code> در دسترس است.</li>
          <li>علامت قیمت کارت محصول (CPC) از کتابخانه رسانه انتخاب می‌شود. صفحه تک‌محصول (KND Elementor) از SVG داخلی تومان استفاده می‌کند.</li>
          <li>پس از فعال‌سازی زبان جدید، هوش مصنوعی می‌تواند به آن زبان ترجمه کند.</li>
          <li>شورت‌کد تغییر زبان: <code dir="ltr">[polymart_language_switcher]</code></li>
        </ul>
      </aside>
    </Layout>
  );
}
