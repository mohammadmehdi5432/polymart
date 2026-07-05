const config = window.polymartAiSettings ?? {};

function StatusBadge({ active, label }) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${
        active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
      }`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-green-500' : 'bg-gray-400'}`} />
      {label}
    </span>
  );
}

export default function PluginStatusBar() {
  return (
    <div className="mb-6 flex flex-wrap items-center gap-2 rounded-lg border border-pmai-border bg-gradient-to-l from-blue-50/80 to-white px-5 py-3">
      <StatusBadge active={config.wooActive} label="ووکامرس" />
      <StatusBadge active={config.woodmartActive} label="وودمارت" />
      <StatusBadge active={Boolean(config.aiConfigured)} label="API هوش مصنوعی" />
      {config.cronDisabled && (
        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900">
          WP-Cron غیرفعال
        </span>
      )}
      {config.version && <span className="text-xs text-pmai-muted">نسخه {config.version}</span>}
      <span className="mr-auto text-xs text-pmai-muted">
        از منوی سمت راست وردپرس بین بخش‌های پلاگین جابه‌جا شوید.
      </span>
    </div>
  );
}
