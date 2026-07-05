import { createRoot } from 'react-dom/client';
import ErrorBoundary from './components/ui/ErrorBoundary';
import App from './App';
import TranslationsApp from './TranslationsApp';
import LanguagesApp from './LanguagesApp';
import AutoTranslateApp from './AutoTranslateApp';
import CurrencyApp from './CurrencyApp';
import ReportApp from './ReportApp';
import LogsApp from './LogsApp';
import './index.css';

const PAGE_COMPONENTS = {
  settings: App,
  languages: LanguagesApp,
  'auto-translate': AutoTranslateApp,
  currency: CurrencyApp,
  translations: TranslationsApp,
  report: ReportApp,
  logs: LogsApp,
};

function resolveMountNode(root) {
  let mount = root.querySelector('#polymart-ai-app');

  if (!mount) {
    mount = document.createElement('div');
    mount.id = 'polymart-ai-app';
    root.appendChild(mount);
  }

  return mount;
}

const root = document.getElementById('polymart-ai-root');

if (root && !root.dataset.reactMounted) {
  root.dataset.reactMounted = '1';

  const config = window.polymartAiSettings ?? {};
  const adminPage =
    config.adminPage ||
    root.getAttribute('data-admin-page') ||
    'settings';

  const RootApp = PAGE_COMPONENTS[adminPage] || App;
  const mount = resolveMountNode(root);

  createRoot(mount).render(
    <ErrorBoundary>
      <RootApp />
    </ErrorBoundary>
  );
}
