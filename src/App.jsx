import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from './components/Layout';
import TabNav from './components/ui/TabNav';
import Notice from './components/ui/Notice';
import Dashboard from './components/tabs/Dashboard';
import TranslationSettings from './components/tabs/TranslationSettings';
import BulkTranslation from './components/tabs/BulkTranslation';
import UiStringsBulkTranslation from './components/tabs/UiStringsBulkTranslation';
import TabHelp from './components/ui/TabHelp';
import { SkeletonFields } from './components/ui/LoadingSkeleton';
import { fetchSettings, saveSettings } from './api/settings';
import { useHashTab } from './hooks/useHashTab';
import { useUnsavedWarning } from './hooks/useUnsavedWarning';

import {
  HiChartBarSquare,
  HiCpuChip,
  HiBolt,
  HiLanguage,
} from './components/ui/icons';

const TAB_IDS = ['dashboard', 'translation', 'bulk', 'ui-strings'];

const TABS = [
  { id: 'dashboard', label: 'داشبورد', Icon: HiChartBarSquare },
  { id: 'translation', label: 'هوش مصنوعی', Icon: HiCpuChip },
  { id: 'bulk', label: 'ترجمه گروهی', Icon: HiBolt },
  { id: 'ui-strings', label: 'رشته‌های UI', Icon: HiLanguage },
];

const SAVEABLE_TABS = new Set(['translation']);

const defaultSettings = {
  translation: {
    ai_provider: 'arvan',
    api_key: '',
    api_key_set: false,
    api_endpoint: '',
    ai_model: 'DeepSeek-V3-2-g6zde',
    gapgpt_api_key: '',
    gapgpt_api_key_set: false,
    gapgpt_ai_model: 'gapgpt-qwen-3.6',
    clear_api_key: false,
    clear_gapgpt_api_key: false,
  },
};

function mergeSettings(base, patch) {
  const translationPatch = patch?.translation ?? {};
  const mergedTranslation = { ...base.translation, ...translationPatch };

  if (patch?.translation && !Object.prototype.hasOwnProperty.call(translationPatch, 'api_key')) {
    mergedTranslation.api_key = '';
  }

  if (patch?.translation && !Object.prototype.hasOwnProperty.call(translationPatch, 'gapgpt_api_key')) {
    mergedTranslation.gapgpt_api_key = '';
  }

  mergedTranslation.clear_api_key = false;
  mergedTranslation.clear_gapgpt_api_key = false;

  return {
    translation: mergedTranslation,
  };
}

function settingsEqual(a, b) {
  return JSON.stringify(a) === JSON.stringify(b);
}

export default function App() {
  const [activeTab, setActiveTab] = useHashTab('dashboard', TAB_IDS);
  const [settings, setSettings] = useState(defaultSettings);
  const [savedSettings, setSavedSettings] = useState(defaultSettings);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  const isDirty = useMemo(
    () => !loading && !settingsEqual(settings, savedSettings),
    [loading, settings, savedSettings]
  );

  useUnsavedWarning(isDirty && SAVEABLE_TABS.has(activeTab));

  useEffect(() => {
    let mounted = true;

    fetchSettings()
      .then((data) => {
        if (mounted) {
          const merged = mergeSettings(defaultSettings, data);
          setSettings(merged);
          setSavedSettings(merged);
        }
      })
      .catch(() => {
        if (mounted) {
          setNotice({ type: 'error', message: 'بارگذاری تنظیمات ناموفق بود.' });
        }
      })
      .finally(() => {
        if (mounted) {
          setLoading(false);
        }
      });

    return () => {
      mounted = false;
    };
  }, []);

  const handleChange = useCallback((section, field, value) => {
    setSettings((prev) => ({
      ...prev,
      [section]: {
        ...prev[section],
        [field]: value,
      },
    }));
  }, []);

  const handleSave = async () => {
    setSaving(true);
    setNotice(null);

    try {
      const payload = {
        translation: {
          ai_provider: settings.translation.ai_provider ?? 'arvan',
          api_endpoint: (settings.translation.api_endpoint ?? '')
            .trim()
            .replace(/…/g, '')
            .replace(/\.\.\./g, ''),
          ai_model: settings.translation.ai_model,
          gapgpt_ai_model: settings.translation.gapgpt_ai_model,
        },
      };

      if (settings.translation.api_key?.trim()) {
        payload.translation.api_key = settings.translation.api_key.trim();
      }

      if (settings.translation.gapgpt_api_key?.trim()) {
        payload.translation.gapgpt_api_key = settings.translation.gapgpt_api_key.trim();
      }

      if (settings.translation.clear_api_key) {
        payload.translation.clear_api_key = true;
      }

      if (settings.translation.clear_gapgpt_api_key) {
        payload.translation.clear_gapgpt_api_key = true;
      }

      const saved = await saveSettings(payload);
      const merged = mergeSettings(settings, saved);
      setSettings(merged);
      setSavedSettings(merged);
      setNotice({ type: 'success', message: 'تنظیمات با موفقیت ذخیره شد.' });
    } catch (error) {
      const message = error?.response?.data?.message || 'ذخیره تنظیمات ناموفق بود.';
      setNotice({ type: 'error', message });
    } finally {
      setSaving(false);
    }
  };

  const showSaveButton = SAVEABLE_TABS.has(activeTab);

  return (
    <Layout
      title="مترجم پلی‌مارت"
      subtitle="ترجمه هوشمند فارسی به زبان‌های مختلف و مدیریت چندزبانه فروشگاه ووکامرس"
    >
      <TabNav tabs={TABS} activeTab={activeTab} onChange={setActiveTab} />

      {isDirty && SAVEABLE_TABS.has(activeTab) && (
        <div className="mt-4">
          <Notice type="warning" message="تغییرات ذخیره نشده‌اند — قبل از خروج «ذخیره تنظیمات» را بزنید." />
        </div>
      )}

      {notice && (
        <div className="mt-4">
          <Notice type={notice.type} message={notice.message} onDismiss={() => setNotice(null)} />
        </div>
      )}

      <div className="mt-6">
        {activeTab === 'dashboard' && <Dashboard onNavigateTab={setActiveTab} />}

        {activeTab === 'translation' &&
          (loading ? (
            <SkeletonFields count={3} />
          ) : (
            <TranslationSettings
              settings={settings.translation}
              onChange={(field, value) => handleChange('translation', field, value)}
            />
          ))}

        {activeTab === 'bulk' && <BulkTranslation />}

        {activeTab === 'ui-strings' && <UiStringsBulkTranslation />}
      </div>

      {showSaveButton && (
        <div className="mt-8 flex items-center gap-3 border-t border-pmai-border pt-6">
          <button
            type="button"
            onClick={handleSave}
            disabled={loading || saving || !isDirty}
            className="rounded-lg bg-pmai-primary px-5 py-2.5 font-medium text-white shadow-sm transition hover:bg-pmai-primary-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            {saving ? 'در حال ذخیره…' : 'ذخیره تنظیمات'}
          </button>
        </div>
      )}

      <TabHelp tab={activeTab} />
    </Layout>
  );
}
