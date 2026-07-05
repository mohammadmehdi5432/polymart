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

export default function TranslationSettings({ settings, onChange }) {
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);
  const [apiKeyInput, setApiKeyInput] = useState('');

  useEffect(() => {
    if (settings.api_key_set && !settings.api_key) {
      setApiKeyInput('');
    }
  }, [settings.api_key, settings.api_key_set]);

  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);

    try {
      const payload = {
        api_endpoint: settings.api_endpoint,
        ai_model: settings.ai_model,
      };

      if (apiKeyInput.trim()) {
        payload.api_key = apiKeyInput.trim();
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

  const handleApiKeyChange = (value) => {
    setApiKeyInput(value);
    onChange('api_key', value);
  };

  const apiKeyPlaceholder = settings.api_key_set
    ? 'کلید API ذخیره شده — برای تغییر، کلید جدید وارد کنید'
    : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';

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

      <div className="rounded-lg border border-pmai-border bg-pmai-surface p-5 shadow-sm">
        <form
          onSubmit={(event) => {
            event.preventDefault();
          }}
        >
        <div className="mb-4 space-y-3">
          <div className="flex items-start gap-2 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-900">
            <HiInformationCircle className="mt-0.5 h-5 w-5 shrink-0" />
            <div className="space-y-2">
              <p>
                در پنل <strong>هوش مصنوعی آروان‌کلاد</strong> یک Machine User بسازید و کلید دسترسی (API Key) را
                دریافت کنید. آدرس <strong>AI Gateway</strong> را از همان پنل کپی کنید — معمولاً به
                <code className="mx-1 rounded bg-blue-100 px-1">/v1</code>
                ختم می‌شود.
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
        </div>

        <Field
          label="کلید API (Machine User)"
          description="کلید دسترسی Machine User از پنل آروان‌کلاد. در دیتابیس ذخیره می‌شود و در پاسخ API نمایش داده نمی‌شود."
          required
        >
          <input
            type="password"
            value={apiKeyInput}
            onChange={(e) => handleApiKeyChange(e.target.value)}
            placeholder={apiKeyPlaceholder}
            className={inputClassName}
            autoComplete="new-password"
            dir="ltr"
          />
          {settings.api_key_set && !apiKeyInput && (
            <p className="mt-1.5 flex items-center gap-1 text-xs text-green-700">
              کلید API قبلاً ذخیره شده است
            </p>
          )}
        </Field>

        <Field
          label="آدرس AI Gateway"
          description="آدرس کامل Gateway از پنل آروان (با /v1 در انتها). اسلش انتهایی لازم نیست."
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

        <Field
          label="مدل هوش مصنوعی"
          description="نام مدل همان‌طور که در پنل آروان تعریف شده. پیش‌فرض: DeepSeek-V3-2-g6zde"
        >
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

        <div className="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4">
          <button
            type="button"
            onClick={handleTest}
            disabled={testing || (!settings.api_key_set && !apiKeyInput.trim()) || !settings.api_endpoint}
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
                تست اتصال API
              </>
            )}
          </button>
        </div>
        </form>
      </div>
    </section>
  );
}
