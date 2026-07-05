const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};

function Step({ done, label, href, onClick }) {
  const content = (
    <>
      <span
        className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
          done ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-600'
        }`}
      >
        {done ? '✓' : '•'}
      </span>
      <span className={done ? 'text-gray-500 line-through' : 'text-gray-900'}>{label}</span>
    </>
  );

  if (href) {
    return (
      <a href={href} className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 transition hover:bg-blue-50">
        {content}
      </a>
    );
  }

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        className="flex w-full items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right transition hover:bg-blue-50"
      >
        {content}
      </button>
    );
  }

  return <div className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3">{content}</div>;
}

export default function OnboardingChecklist({ aiConfigured, languageCount, needsTranslation, onNavigateTab }) {
  const steps = [
    {
      id: 'api',
      done: aiConfigured,
      label: 'پیکربندی API هوش مصنوعی',
      onClick: () => onNavigateTab?.('translation'),
    },
    {
      id: 'languages',
      done: languageCount > 1,
      label: 'افزودن حداقل یک زبان مقصد',
      href: adminUrls.languages,
    },
    {
      id: 'translate',
      done: needsTranslation <= 0,
      label: 'ترجمه محتوای موجود',
      href: adminUrls.autoTranslate,
    },
  ];

  const completed = steps.filter((step) => step.done).length;

  if (completed === steps.length) {
    return (
      <div className="rounded-lg border border-green-200 bg-green-50 p-5 lg:col-span-2">
        <h3 className="text-base font-semibold text-green-900">راه‌اندازی اولیه کامل شد</h3>
        <p className="mt-1 text-sm text-green-800">
          API، زبان‌ها و ترجمه محتوا آماده است. از «مدیریت ترجمه» برای ویرایش دستی استفاده کنید.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-indigo-200 bg-indigo-50/60 p-5 lg:col-span-2">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-base font-semibold text-indigo-950">راه‌اندازی اولیه</h3>
          <p className="mt-1 text-sm text-indigo-900">
            {completed} از {steps.length} مرحله انجام شده
          </p>
        </div>
        <div className="h-2 w-32 overflow-hidden rounded-full bg-indigo-100">
          <div
            className="h-full rounded-full bg-indigo-600 transition-all"
            style={{ width: `${Math.round((completed / steps.length) * 100)}%` }}
          />
        </div>
      </div>
      <div className="grid gap-2 sm:grid-cols-3">
        {steps.map((step) => (
          <Step key={step.id} {...step} />
        ))}
      </div>
    </div>
  );
}
