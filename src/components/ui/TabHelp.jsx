import SwitcherPreview from './SwitcherPreview';
import CopyButton from './CopyButton';

const SHORTCODE = '[polymart_language_switcher]';

const SHORTCODE_TABS = new Set(['dashboard', 'translation', 'bulk', 'languages-help']);

function ShortcodeBlock() {
  return (
    <div className="mt-4 rounded border border-blue-200 bg-white p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-medium text-gray-900">شورت‌کد تغییر زبان</p>
        <CopyButton text={SHORTCODE} label="کپی شورت‌کد" />
      </div>
      <p className="mt-1 text-pmai-muted">
        برای نمایش سوییچر زبان در هدر یا هر بخش دیگر سایت، این شورت‌کد را قرار دهید:
      </p>
      <code
        className="mt-2 block rounded border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800"
        dir="ltr"
      >
        {SHORTCODE}
      </code>
      <ul className="mt-2 list-inside list-disc space-y-1 text-pmai-muted">
        <li>در ویرایشگر بلوک: بلوک «شورت‌کد» یا «HTML سفارشی»</li>
        <li>در قالب وودمارت: HTML Blocks در هدر (Theme Settings → Header)</li>
        <li>در ویجت: ابزارک «شورت‌کد» یا «HTML سفارشی»</li>
      </ul>

      <SwitcherPreview />
    </div>
  );
}

const TAB_HELP = {
  dashboard: {
    title: 'راهنمای داشبورد',
    steps: [
      'خلاصه وضعیت ترجمه‌های سایت را در کارت‌های آماری ببینید.',
      'بخش «وضعیت سیستم» نشان می‌دهد آیا API هوش مصنوعی و WP-Cron فعال هستند.',
      'بخش «نگهداری و صف پس‌زمینه» تعداد کارهای معوق را نشان می‌دهد — قبل از پروداکشن «پردازش صف‌ها» را بزنید.',
      'از «دسترسی سریع» می‌توانید به تنظیمات AI، ترجمه خودکار و مدیریت ترجمه بروید.',
      'آدرس‌های زبان‌های فعال برای تست صفحات چندزبانه نمایش داده می‌شوند.',
    ],
    note: 'اگر DISABLE_WP_CRON فعال است، حتماً cron واقعی سرور را تنظیم کنید یا از دکمه پردازش صف در داشبورد استفاده کنید.',
    showShortcode: true,
  },
  translation: {
    title: 'راهنمای تنظیمات هوش مصنوعی',
    steps: [
      'در پنل هوش مصنوعی آروان‌کلاد یک Machine User بسازید و کلید دسترسی (API Key) را کپی کنید.',
      'آدرس AI Gateway را از همان پنل بگیرید — آدرسی که با /v1 تمام می‌شود.',
      'نام مدل پیش‌فرض: DeepSeek-V3-2-g6zde (در صورت نیاز می‌توانید تغییر دهید).',
      'روی «ذخیره تنظیمات» کلیک کنید، سپس «تست اتصال API» را بزنید.',
      'برای ترجمه تکی: محصول یا نوشته را ویرایش کنید → متاباکس ترجمه → «تولید با هوش مصنوعی».',
    ],
    note: 'پس از فعال‌سازی API، از صفحه «ترجمه خودکار» برای ترجمه مرحله‌ای استفاده کنید.',
    showShortcode: true,
  },
  bulk: {
    title: 'راهنمای ترجمه گروهی',
    steps: [
      'ابتدا در تب «تنظیمات هوش مصنوعی» کلید API و آدرس را ذخیره کنید.',
      'این تب فقط محصولات و نوشته‌های منتشرشده بدون عنوان ترجمه را لیست می‌کند.',
      'روی «شروع ترجمه گروهی» بزنید — هر مورد جداگانه ترجمه می‌شود تا سرور دچار تایم‌اوت نشود.',
      'برای ترجمه با قابلیت ادامه پس از قطعی شبکه، از صفحه «ترجمه خودکار» در منوی وردپرس استفاده کنید.',
    ],
    note: 'ترجمه خودکار برای اجراهای طولانی و resume توصیه می‌شود؛ ترجمه گروهی برای دسته‌های کوچک مناسب است.',
    showShortcode: true,
  },
  'ui-strings': {
    title: 'راهنمای ترجمه رشته‌های UI',
    steps: [
      'ابتدا API هوش مصنوعی را در تب «هوش مصنوعی» پیکربندی کنید.',
      'روی «اسکن رشته‌ها» بزنید — ووکامرس/وودمارت از فایل languages/*.pot خوانده می‌شوند (نه کل سورس).',
      'فایل .pot اختیاری است؛ اگر languages/*.pot وجود داشته باشد، آن‌ها هم ادغام می‌شوند.',
      'سپس «شروع ترجمه گروهی UI» را بزنید — ترجمه‌ها در دیتابیس ذخیره می‌شوند و فرانت فقط از DB می‌خواند.',
    ],
    note: 'فقط رشته‌های storefront (سبد، چک‌اوت، حساب، هدر) اسکن می‌شوند — پنل ادمین وردپرس/ووکامرس حذف شده‌اند. برای سبد خرید، ترجمه خودکار منو + runtime gettext هم فعال است.',
  },
};

export default function TabHelp({ tab }) {
  const help = TAB_HELP[tab];

  if (!help) {
    return null;
  }

  return (
    <aside
      className="mt-8 rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm leading-relaxed text-blue-950"
      aria-label="راهنما"
    >
      <h3 className="text-base font-semibold text-blue-900">{help.title}</h3>

      <ol className="mt-3 list-inside list-decimal space-y-2 text-blue-900">
        {help.steps.map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ol>

      {help.note && (
        <p className="mt-3 rounded border border-blue-200 bg-white px-3 py-2 text-blue-800">
          <span className="font-medium">نکته: </span>
          {help.note}
        </p>
      )}

      {help.showShortcode && <ShortcodeBlock />}
    </aside>
  );
}

export { TAB_HELP, SHORTCODE_TABS };
