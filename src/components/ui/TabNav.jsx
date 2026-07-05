export default function TabNav({ tabs, activeTab, onChange }) {
  return (
    <nav
      className="flex flex-wrap gap-1 border-b border-pmai-border"
      aria-label="تب‌های تنظیمات"
      role="tablist"
    >
      {tabs.map((tab) => {
        const isActive = tab.id === activeTab;
        const Icon = tab.Icon;

        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => onChange(tab.id)}
            className={`-mb-px inline-flex items-center gap-1.5 rounded-t-lg border px-4 py-2.5 text-sm font-medium transition ${
              isActive
                ? 'border-pmai-border border-b-white bg-white text-pmai-primary shadow-sm'
                : 'border-transparent text-pmai-muted hover:border-gray-200 hover:bg-gray-50 hover:text-gray-900'
            } ${tab.disabled ? 'cursor-not-allowed opacity-60' : ''}`}
            disabled={tab.disabled}
          >
            {Icon && <Icon className="h-4 w-4 shrink-0" />}
            {tab.label}
            {tab.badge && (
              <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                {tab.badge}
              </span>
            )}
          </button>
        );
      })}
    </nav>
  );
}
