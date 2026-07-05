import PluginStatusBar from './ui/PluginStatusBar';
import { HiGlobeAlt } from './ui/icons';

export default function Layout({ title, subtitle, children }) {
  return (
    <div className="max-w-7xl font-sans" dir="rtl">
      <header className="mb-2">
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-pmai-primary text-white shadow-sm">
            <HiGlobeAlt className="h-5 w-5" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
            {subtitle && <p className="mt-0.5 text-sm text-pmai-muted">{subtitle}</p>}
          </div>
        </div>
      </header>

      <PluginStatusBar />

      <main>{children}</main>
    </div>
  );
}
