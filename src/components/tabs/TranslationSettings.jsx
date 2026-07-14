import { useEffect, useState } from 'react';
import { testConnection } from '../../api/dashboard';
import Notice from '../ui/Notice';
import { HiInformationCircle, HiSignal } from '../ui/icons';

function Field({ label, description, children, required = false }) {
  return (
    <div className="grid gap-1 border-b border-gray-100 py-4 last:border-0 sm:grid-cols-3 sm:gap-4">
      <div className="sm:col-span-1">
        <label className="block font-medium text-gray-900">
          {label}
          {required && <span className="mr-1 text-red-500">*</span>}
        </label>
        {description && <p className="mt-1 text-xs leading-relaxed text-pmai-muted">{description}</p>}
      </div>
      <div className="sm:col-span-2">{children}</div>
    </div>
  );
}

const inputClassName =
  'w-full max-w-lg rounded-lg border border-pmai-border px-3 py-2.5 text-sm transition focus:border-pmai-primary focus:outline-none focus:ring-2 focus:ring-pmai-primary/20';

const PROVIDERS = [
  { id: 'arvan', label: 'آروان‌کلاد' },
  { id: 'gapgpt', label: 'گپ GPT' },
];

function ProviderSection({ title, active, children }) {
  return (
    <div
      className={`rounded-lg border p-5 transition ${
        active ? 'border-pmai-primary bg-blue-50/40 shadow-sm' : 'border-pmai-border bg-white'
      }`}
    >
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-base font-semibold text-gray-900">{title}</h3>
        {active && (
          <span className="rounded-full bg-pmai-primary px-2.5 py-0.5 text-xs font-medium text-white">
            فعال برای ترجمه
          </span>
        )}
      </div>
      {children}
    </div>
  );
}

export default function TranslationSettings({ settings, onChange }) {
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);
  const [arvanKeyInput, setArvanKeyInput] = useState('');
  const [gapgptKeyInput, setGapgptKeyInput] = useState('');

  const activeProvider = settings.ai_provider === 'gapgpt' ? 'gapgpt' : 'arvan';

  useEffect(() => {
    if (settings.api_key_set && !settings.api_key) {
      setArvanKeyInput('');
    }
  }, [settings.api_key, settings.api_key_set]);

  useEffect(() => {
    if (settings.gapgpt_api_key_set && !settings.gapgpt_api_key) {
      setGapgptKeyInput('');
    }
  }, [settings.gapgpt_api_key, settings.gapgpt_api_key_set]);

  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);

    try {
      const payload = { ai_provider: activeProvider };

      if (activeProvider === 'gapgpt') {
        payload.gapgpt_ai_model = settings.gapgpt_ai_model;
        if (gapgptKeyInput.trim()) {
          payload.gapgpt_api_key = gapgptKeyInput.trim();
        }
      } else {
        payload.api_endpoint = settings.api_endpoint;
        payload.ai_model = settings.ai_model;
        if (arvanKeyInput.trim()) {
          payload.api_key = arvanKeyInput.trim();
        }
      }

      const result = await testConnection(payload);
      setTestResult({ type: 'success', message: result.message });
    } catch (error) {
      const message = error?.response?.data?.message || 'تست اتصال ناموفق بود.';
      setTestResult({ type: 'error', message });
    } finally {
      setTesting(false);
    }
  };

  const handleArvanKeyChange = (value) => {
    setArvanKeyInput(value);
    onChange('api_key', value);
    if (settings.clear_api_key) {
      onChange('clear_api_key', false);
    }
  };

  const handleGapgptKeyChange = (value) => {
    setGapgptKeyInput(value);
    onChange('gapgpt_api_key', value);
    if (settings.clear_gapgpt_api_key) {
      onChange('clear_gapgpt_api_key', false);
    }
  };

  const clearArvanKey = () => {
    setArvanKeyInput('');
    onChange('api_key', '');
    onChange('clear_api_key', true);
  };

  const clearGapgptKey = () => {
    setGapgptKeyInput('');
    onChange('gapgpt_api_key', '');
    onChange('clear_gapgpt_api_key', true);
  };

  const arvanKeyPlaceholder = settings.api_key_set && !settings.clear_api_key && !arvanKeyInput
    ? 'کلید API ذخیره شده — برای تغییر، کلید جدید وارد کنید'
    : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';

  const gapgptKeyPlaceholder = settings.gapgpt_api_key_set && !settings.clear_gapgpt_api_key && !gapgptKeyInput
    ? 'کلید API ذخیره شده — برای تغییر، کلید جدید وارد کنید'
    : 'sk-...';

  const canTest =
    activeProvider === 'gapgpt'
      ? (settings.gapgpt_api_key_set && !settings.clear_gapgpt_api_key) || gapgptKeyInput.trim()
      : ((settings.api_key_set && !settings.clear_api_key) || arvanKeyInput.trim()) && settings.api_endpoint;

  return (
    <section aria-labelledby="translation-settings-heading">
      <h2 id="translation-settings-heading" className="sr-only">
        تنظیمات ترجمه هوش مصنوعی
      </h2>

      {testResult && (
        <div className="mb-4">
          <Notice
            type={testResult.type}
            message={testResult.message}
            onDismiss={() => setTestResult(null)}
          />
        </div>
      )}

      <div className="space-y-6">
        <div className="rounded-lg border border-pmai-border bg-pmai-surface p-5 shadow-sm">
          <Field
            label="سرویس ترجمه"
            description="ترجمه گروهی، Elementor و سایر بخش‌ها از سرویس انتخاب‌شده استفاده می‌کنند."
          >
            <div className="inline-flex rounded-lg border border-pmai-border bg-gray-50 p-1">
              {PROVIDERS.map((provider) => (
                <button
                  key={provider.id}
                  type="button"
                  onClick={() => onChange('ai_provider', provider.id)}
                  className={`rounded-md px-4 py-2 text-sm font-medium transition ${
                    activeProvider === provider.id
                      ? 'bg-white text-pmai-primary shadow-sm'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  {provider.label}
                </button>
              ))}
            </div>
          </Field>
        </div>

        <ProviderSection title="آروان‌کلاد" active={activeProvider === 'arvan'}>
          <div className="mb-4 flex items-start gap-2 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-900">
            <HiInformationCircle className="mt-0.5 h-5 w-5 shrink-0" />
            <div className="space-y-2">
              <p>
                در پنل <strong>هوش مصنوعی آروان‌کلاد</strong> یک Machine User بسازید و کلید دسترسی (API Key) را
                دریافت کنید. آدرس <strong>AI Gateway</strong> را از همان پنل کپی کنید.
              </p>
              <p className="text-xs leading-relaxed text-blue-800">
                مثال آدرس Gateway:
                <br />
                <span className="font-mono" dir="ltr">
                  https://arvancloudai.ir/gateway/models/DeepSeek-V3-2-g6zde/your-token-id/v1
                </span>
              </p>
            </div>
          </div>

          <Field
            label="کلید API (Machine User)"
            description="کلید دسترسی Machine User از پنل آروان‌کلاد."
            required
          >
            <div className="flex max-w-lg flex-wrap gap-2">
              <input
                type="password"
                value={arvanKeyInput}
                onChange={(e) => handleArvanKeyChange(e.target.value)}
                placeholder={arvanKeyPlaceholder}
                className={`${inputClassName} min-w-0 flex-1`}
                autoComplete="new-password"
                dir="ltr"
              />
              {(settings.api_key_set || arvanKeyInput) && !settings.clear_api_key && (
                <button
                  type="button"
                  onClick={clearArvanKey}
                  className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 transition hover:bg-red-50"
                >
                  پاک کردن کلید
                </button>
              )}
            </div>
            {settings.api_key_set && !settings.clear_api_key && !arvanKeyInput && (
              <p className="mt-1.5 flex items-center gap-1 text-xs text-green-700">
                کلید API قبلاً ذخیره شده است
              </p>
            )}
            {settings.clear_api_key && (
              <p className="mt-1.5 text-xs text-amber-700">کلید آروان با ذخیره تنظیمات پاک می‌شود.</p>
            )}
          </Field>

          <Field
            label="آدرس AI Gateway"
            description="آدرس کامل Gateway از پنل آروان (با /v1 در انتها)."
            required
          >
            <textarea
              value={settings.api_endpoint ?? ''}
              onChange={(e) => onChange('api_endpoint', e.target.value)}
              placeholder="https://arvancloudai.ir/gateway/models/DeepSeek-V3-2-g6zde/your-token-id/v1"
              className={`${inputClassName} min-h-[4.5rem] resize-y font-mono text-xs`}
              autoComplete="off"
              dir="ltr"
              rows={2}
            />
          </Field>

          <Field label="مدل هوش مصنوعی" description="پیش‌فرض: DeepSeek-V3-2-g6zde">
            <input
              type="text"
              value={settings.ai_model ?? 'DeepSeek-V3-2-g6zde'}
              onChange={(e) => onChange('ai_model', e.target.value)}
              placeholder="DeepSeek-V3-2-g6zde"
              className={inputClassName}
              autoComplete="off"
              dir="ltr"
            />
          </Field>
        </ProviderSection>

        <ProviderSection title="گپ GPT" active={activeProvider === 'gapgpt'}>
          <div className="mb-4 flex items-start gap-2 rounded-lg bg-violet-50 px-4 py-3 text-sm text-violet-900">
            <HiInformationCircle className="mt-0.5 h-5 w-5 shrink-0" />
            <div className="space-y-2">
              <p>
                API سازگار با OpenAI از{' '}
                <strong>GapGPT</strong>. کلید API را از پنل gapgpt.app دریافت کنید.
              </p>
              <p className="text-xs leading-relaxed text-violet-800">
                آدرس API:
                <br />
                <span className="font-mono" dir="ltr">
                  https://api.gapgpt.app/v1
                </span>
              </p>
            </div>
          </div>

          <Field label="کلید API" description="کلید API از پنل GapGPT." required>
            <div className="flex max-w-lg flex-wrap gap-2">
              <input
                type="password"
                value={gapgptKeyInput}
                onChange={(e) => handleGapgptKeyChange(e.target.value)}
                placeholder={gapgptKeyPlaceholder}
                className={`${inputClassName} min-w-0 flex-1`}
                autoComplete="new-password"
                dir="ltr"
              />
              {(settings.gapgpt_api_key_set || gapgptKeyInput) && !settings.clear_gapgpt_api_key && (
                <button
                  type="button"
                  onClick={clearGapgptKey}
                  className="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 transition hover:bg-red-50"
                >
                  پاک کردن کلید
                </button>
              )}
            </div>
            {settings.gapgpt_api_key_set && !settings.clear_gapgpt_api_key && !gapgptKeyInput && (
              <p className="mt-1.5 flex items-center gap-1 text-xs text-green-700">
                کلید API قبلاً ذخیره شده است
              </p>
            )}
            {settings.clear_gapgpt_api_key && (
              <p className="mt-1.5 text-xs text-amber-700">کلید GapGPT با ذخیره تنظیمات پاک می‌شود.</p>
            )}
          </Field>

          <Field label="مدل هوش مصنوعی" description="پیش‌فرض: gapgpt-qwen-3.6">
            <input
              type="text"
              value={settings.gapgpt_ai_model ?? 'gapgpt-qwen-3.6'}
              onChange={(e) => onChange('gapgpt_ai_model', e.target.value)}
              placeholder="gapgpt-qwen-3.6"
              className={inputClassName}
              autoComplete="off"
              dir="ltr"
            />
          </Field>
        </ProviderSection>

        <div className="rounded-lg border border-pmai-border bg-pmai-surface p-5 shadow-sm">
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={handleTest}
              disabled={testing || !canTest}
              className="inline-flex items-center gap-2 rounded-lg border border-pmai-primary bg-white px-4 py-2 text-sm font-medium text-pmai-primary transition hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {testing ? (
                <>
                  <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-pmai-primary border-t-transparent" />
                  در حال تست…
                </>
              ) : (
                <>
                  <HiSignal className="h-4 w-4" />
                  تست اتصال ({activeProvider === 'gapgpt' ? 'گپ GPT' : 'آروان'})
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
