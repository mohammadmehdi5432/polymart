import { useEffect, useState } from 'react';
import { fetchLanguages } from '../api/languages';

export const FALLBACK_TARGET_LANGUAGES = [
  { code: 'en', native_name: 'English', name: 'انگلیسی', enabled: true, is_default: false },
];

export function getTargetLanguageOptions(languages = []) {
  const options = (languages ?? []).filter((lang) => lang.enabled && !lang.is_default);
  return options.length ? options : FALLBACK_TARGET_LANGUAGES;
}

export function getLanguageLabel(code, options = []) {
  const match = options.find((lang) => lang.code === code);
  return match?.native_name || match?.name || code?.toUpperCase() || '—';
}

export function useTargetLanguages(initialCode = 'en') {
  const [langOptions, setLangOptions] = useState([]);
  const [targetLang, setTargetLang] = useState(initialCode);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let mounted = true;

    setLoading(true);
    fetchLanguages()
      .then((data) => {
        if (!mounted) {
          return;
        }

        const options = getTargetLanguageOptions(data.languages ?? []);
        setLangOptions(options);
        setTargetLang((prev) =>
          options.some((lang) => lang.code === prev) ? prev : options[0]?.code ?? 'en'
        );
        setError(null);
      })
      .catch(() => {
        if (!mounted) {
          return;
        }

        setLangOptions(FALLBACK_TARGET_LANGUAGES);
        setTargetLang('en');
        setError('بارگذاری زبان‌ها ناموفق بود. فقط انگلیسی در دسترس است.');
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

  return {
    langOptions,
    targetLang,
    setTargetLang,
    loading,
    error,
    targetLabel: getLanguageLabel(targetLang, langOptions),
    usingFallback: langOptions === FALLBACK_TARGET_LANGUAGES || langOptions.length === 0,
  };
}
