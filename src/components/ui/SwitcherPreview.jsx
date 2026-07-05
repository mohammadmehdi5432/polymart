import { useEffect, useState } from 'react';
import { fetchLanguages } from '../../api/languages';

const DROPDOWN_THRESHOLD = 2;

function FlagOrCode({ lang }) {
  if (lang.flag_url) {
    return (
      <img
        src={lang.flag_url}
        alt=""
        className="h-[22px] w-[22px] shrink-0 rounded-full border-2 border-white object-cover shadow-sm"
      />
    );
  }

  return (
    <span className="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-gray-200 text-[9px] font-bold uppercase text-gray-700">
      {lang.code}
    </span>
  );
}

function InlinePreview({ languages, activeCode }) {
  return (
    <nav className="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-black/[0.04] p-1" dir="rtl">
      {languages.map((lang) => {
        const isActive = lang.code === activeCode;
        return (
          <span
            key={lang.code}
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ${
              isActive ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 opacity-70'
            }`}
          >
            <FlagOrCode lang={lang} />
            <span>{lang.native_name || lang.name}</span>
          </span>
        );
      })}
    </nav>
  );
}

function DropdownPreview({ languages, activeCode }) {
  const active = languages.find((l) => l.code === activeCode) ?? languages[0];

  return (
    <div className="relative inline-block" dir="rtl">
      <div className="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 shadow-md">
        <FlagOrCode lang={active} />
        <span className="text-xs font-semibold text-gray-900">{active?.native_name || active?.name}</span>
        <span className="ml-1 h-0 w-0 border-x-4 border-t-[5px] border-x-transparent border-t-gray-500 opacity-60" />
      </div>

      <ul className="absolute left-0 right-0 top-full z-10 mt-2 min-w-[200px] rounded-xl border border-gray-200 bg-white p-1.5 shadow-xl">
        {languages.map((lang) => {
          const isActive = lang.code === activeCode;
          return (
            <li
              key={lang.code}
              className={`flex items-center gap-2.5 rounded-lg px-3 py-2 ${
                isActive ? 'bg-blue-50' : ''
              }`}
            >
              <FlagOrCode lang={lang} />
              <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold text-gray-900">{lang.native_name || lang.name}</p>
                {lang.name && lang.name !== lang.native_name && (
                  <p className="text-[10px] text-gray-500">{lang.name}</p>
                )}
              </div>
              {isActive && (
                <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-pmai-primary text-[8px] text-white">
                  ✓
                </span>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export default function SwitcherPreview({ activeCode = 'fa' }) {
  const [languages, setLanguages] = useState([]);

  useEffect(() => {
    fetchLanguages()
      .then((data) => {
        setLanguages((data.languages ?? []).filter((lang) => lang.enabled));
      })
      .catch(() => {});
  }, []);

  if (languages.length < 2) {
    return null;
  }

  const isDropdown = languages.length > DROPDOWN_THRESHOLD;

  return (
    <div className="mt-4 rounded-lg border border-blue-200 bg-white p-4">
      <p className="mb-1 text-sm font-medium text-gray-900">پیش‌نمایش سوییچر زبان</p>
      <p className="mb-3 text-xs text-pmai-muted">
        {isDropdown
          ? 'بیش از ۲ زبان — نمایش منوی کشویی'
          : '۲ زبان — نمایش دکمه‌های کنار هم'}
      </p>
      <div className="flex justify-center rounded-lg bg-gray-100 px-6 py-6" aria-hidden="true">
        {isDropdown ? (
          <DropdownPreview languages={languages} activeCode={activeCode} />
        ) : (
          <InlinePreview languages={languages} activeCode={activeCode} />
        )}
      </div>
      <p className="mt-2 text-center text-xs text-pmai-muted">
        این پیش‌نمایش فقط نمایشی است و قابل کلیک نیست.
      </p>
    </div>
  );
}
