export default function Notice({ type = 'info', message, onDismiss }) {
  if (!message) {
    return null;
  }

  const styles = {
    success: 'border-green-300 bg-green-50 text-green-800',
    error: 'border-red-300 bg-red-50 text-red-800',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    info: 'border-blue-300 bg-blue-50 text-blue-900',
  };

  return (
    <div
      className={`flex items-start justify-between gap-3 rounded-lg border px-4 py-3 ${styles[type] ?? styles.info}`}
      role="alert"
    >
      <span>{message}</span>
      {onDismiss && (
        <button
          type="button"
          onClick={onDismiss}
          className="shrink-0 text-lg leading-none opacity-60 hover:opacity-100"
          aria-label="بستن"
        >
          ×
        </button>
      )}
    </div>
  );
}
