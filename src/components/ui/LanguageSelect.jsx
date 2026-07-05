export default function LanguageSelect({
  value,
  onChange,
  options = [],
  disabled = false,
  loading = false,
  className = '',
  id,
}) {
  if (loading) {
    return (
      <select
        id={id}
        disabled
        className={`rounded-lg border border-pmai-border px-3 py-2 text-sm text-pmai-muted ${className}`}
      >
        <option>در حال بارگذاری زبان‌ها…</option>
      </select>
    );
  }

  if (!options.length) {
    return (
      <select
        id={id}
        disabled
        className={`rounded-lg border border-pmai-border px-3 py-2 text-sm text-pmai-muted ${className}`}
      >
        <option>زبان مقصدی فعال نیست</option>
      </select>
    );
  }

  return (
    <select
      id={id}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      disabled={disabled}
      className={`rounded-lg border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary disabled:cursor-not-allowed disabled:opacity-60 ${className}`}
    >
      {options.map((lang) => (
        <option key={lang.code} value={lang.code}>
          {lang.native_name || lang.name} ({lang.code})
        </option>
      ))}
    </select>
  );
}
