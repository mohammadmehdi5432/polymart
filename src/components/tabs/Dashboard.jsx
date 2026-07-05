import { useCallback, useEffect, useState } from 'react';
import { fetchDashboard } from '../../api/dashboard';
import StatCard from '../ui/StatCard';
import { SkeletonCard } from '../ui/LoadingSkeleton';
import Notice from '../ui/Notice';
import OnboardingChecklist from '../ui/OnboardingChecklist';
import {
  HiCpuChip,
  HiBolt,
  HiDocumentText,
  HiHome,
  HiCurrencyDollar,
} from '../ui/icons';
import QueueMaintenance from './QueueMaintenance';

const config = window.polymartAiSettings ?? {};
const adminUrls = config.adminUrls ?? {};
const bootAiConfigured = Boolean(config.aiConfigured);

export default function Dashboard({ onNavigateTab }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const loadDashboard = useCallback(() => {
    return fetchDashboard()
      .then((dashboard) => {
        setData(dashboard);
        return dashboard;
      })
      .catch(() => {
        setError('بارگذاری داشبورد ناموفق بود.');
      });
  }, []);

  useEffect(() => {
    let mounted = true;

    loadDashboard().finally(() => {
      if (mounted) {
        setLoading(false);
      }
    });

    return () => {
      mounted = false;
    };
  }, [loadDashboard]);

  const handleQueueUpdated = useCallback((queue) => {
    if (!queue) {
      loadDashboard();
      return;
    }

    setData((prev) => (prev ? { ...prev, queue } : prev));
  }, [loadDashboard]);

  const stats = data?.stats ?? {};
  const languages = (data?.languages ?? []).filter((lang) => lang.enabled);
  const needsTranslation = (stats.untranslated ?? 0) + (stats.partial ?? 0);

  return (
    <section aria-labelledby="dashboard-heading">
      <h2 id="dashboard-heading" className="sr-only">
        داشبورد
      </h2>

      {error && <Notice type="error" message={error} />}

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {loading ? (
          Array.from({ length: 4 }).map((_, i) => <SkeletonCard key={i} />)
        ) : (
          <>
            <StatCard label="کل موارد قابل ترجمه" value={stats.total ?? 0} />
            <StatCard label="ترجمه‌نشده" value={stats.untranslated ?? 0} color="text-red-700" />
            <StatCard label="ناقص" value={stats.partial ?? 0} color="text-amber-700" />
            <StatCard label="ترجمه‌شده" value={stats.translated ?? 0} color="text-green-700" />
          </>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <OnboardingChecklist
          aiConfigured={Boolean(data?.ai_configured ?? bootAiConfigured)}
          languageCount={languages.length}
          needsTranslation={needsTranslation}
          onNavigateTab={onNavigateTab}
        />

        <div className="rounded-lg border border-pmai-border bg-white p-5">
          <h3 className="text-base font-semibold text-gray-900">وضعیت سیستم</h3>
          <ul className="mt-4 space-y-3 text-sm">
            <li className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <span className="text-pmai-muted">اتصال هوش مصنوعی</span>
              <span
                className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  data?.ai_configured ?? bootAiConfigured
                    ? 'bg-green-100 text-green-800'
                    : 'bg-red-100 text-red-800'
                }`}
              >
                {data?.ai_configured ?? bootAiConfigured ? 'پیکربندی شده' : 'نیاز به تنظیم'}
              </span>
            </li>
            <li className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <span className="text-pmai-muted">زبان‌های فعال</span>
              <span className="text-gray-900">{languages.length}</span>
            </li>
            <li className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <span className="text-pmai-muted">مدل هوش مصنوعی</span>
              <span className="font-mono text-xs text-gray-900" dir="ltr">
                {data?.ai_model || 'DeepSeek-V3-2-g6zde'}
              </span>
            </li>
            <li className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <span className="text-pmai-muted">WP-Cron</span>
              <span
                className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  data?.cron_disabled
                    ? 'bg-amber-100 text-amber-900'
                    : 'bg-green-100 text-green-800'
                }`}
              >
                {data?.cron_disabled ? 'غیرفعال — cron سرور لازم است' : 'فعال'}
              </span>
            </li>
            <li className="flex items-center justify-between gap-3">
              <span className="text-pmai-muted">نسخه پلاگین</span>
              <span className="text-gray-900">{data?.plugin_version}</span>
            </li>
          </ul>
        </div>

        <div className="rounded-lg border border-pmai-border bg-white p-5">
          <h3 className="text-base font-semibold text-gray-900">دسترسی سریع</h3>
          <div className="mt-4 grid gap-2">
            <button
              type="button"
              onClick={() => onNavigateTab?.('translation')}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiCpuChip className="h-5 w-5 shrink-0 text-pmai-primary" />
              <div>
                <p className="font-medium text-gray-900">تنظیمات هوش مصنوعی</p>
                <p className="text-xs text-pmai-muted">کلید API، آدرس و مدل</p>
              </div>
            </button>
            <button
              type="button"
              onClick={() => onNavigateTab?.('bulk')}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiBolt className="h-5 w-5 shrink-0 text-amber-600" />
              <div>
                <p className="font-medium text-gray-900">ترجمه گروهی</p>
                <p className="text-xs text-pmai-muted">
                  {needsTranslation} مورد نیاز به ترجمه
                  {(stats.partial ?? 0) > 0 ? ` (${stats.partial} ناقص)` : ''}
                </p>
              </div>
            </button>
            <a
              href={adminUrls.languages}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiHome className="h-5 w-5 shrink-0 text-green-600" />
              <div>
                <p className="font-medium text-gray-900">مدیریت زبان‌ها</p>
                <p className="text-xs text-pmai-muted">افزودن زبان و پرچم</p>
              </div>
            </a>
            <a
              href={adminUrls.currency}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiCurrencyDollar className="h-5 w-5 shrink-0 text-emerald-600" />
              <div>
                <p className="font-medium text-gray-900">نرخ ارز</p>
                <p className="text-xs text-pmai-muted">دریافت و به‌روزرسانی نرخ دلار</p>
              </div>
            </a>
            <a
              href={adminUrls.translations}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiDocumentText className="h-5 w-5 shrink-0 text-pmai-primary" />
              <div>
                <p className="font-medium text-gray-900">مدیریت ترجمه</p>
                <p className="text-xs text-pmai-muted">ویرایش دستی و تولید AI تکی</p>
              </div>
            </a>
            <a
              href={adminUrls.autoTranslate}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiBolt className="h-5 w-5 shrink-0 text-amber-600" />
              <div>
                <p className="font-medium text-gray-900">ترجمه خودکار</p>
                <p className="text-xs text-pmai-muted">ترجمه مرحله‌ای با قابلیت ادامه</p>
              </div>
            </a>
            <a
              href={adminUrls.report}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiDocumentText className="h-5 w-5 shrink-0 text-indigo-600" />
              <div>
                <p className="font-medium text-gray-900">گزارش ترجمه</p>
                <p className="text-xs text-pmai-muted">تاریخچه ترجمه‌های انجام‌شده</p>
              </div>
            </a>
            <a
              href={adminUrls.logs}
              className="flex items-center gap-3 rounded-lg border border-pmai-border px-4 py-3 text-right text-sm transition hover:bg-blue-50"
            >
              <HiDocumentText className="h-5 w-5 shrink-0 text-red-600" />
              <div>
                <p className="font-medium text-gray-900">لاگ‌ها</p>
                <p className="text-xs text-pmai-muted">خطاها و رویدادهای سیستم</p>
              </div>
            </a>
          </div>
        </div>

        {languages.length > 0 && (
          <div className="rounded-lg border border-blue-200 bg-blue-50 p-5 lg:col-span-2">
            <h3 className="text-base font-semibold text-blue-900">زبان‌های فعال</h3>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {languages.map((lang) => (
                <div key={lang.code} className="flex items-center gap-3 rounded border border-blue-200 bg-white p-3">
                  {lang.flag_url ? (
                    <img
                      src={lang.flag_url}
                      alt=""
                      className="h-8 w-8 rounded-full object-cover"
                    />
                  ) : (
                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-bold uppercase">
                      {lang.code}
                    </span>
                  )}
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-gray-900">{lang.native_name}</p>
                    <code className="text-xs text-pmai-muted" dir="ltr">
                      {lang.url || '/'}
                    </code>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {!loading && (
          <QueueMaintenance
            queue={data?.queue}
            cronDisabled={Boolean(data?.cron_disabled)}
            cronUrl={data?.cron_url}
            onQueueUpdated={handleQueueUpdated}
          />
        )}
      </div>
    </section>
  );
}
