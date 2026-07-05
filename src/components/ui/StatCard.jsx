export default function StatCard({ label, value, color = 'text-gray-900', icon, onClick, active = false }) {
  const baseClass =
    'rounded-lg border bg-white px-4 py-4 transition-shadow hover:shadow-sm';
  const borderClass = active
    ? 'border-pmai-primary ring-1 ring-pmai-primary/20'
    : 'border-pmai-border';

  const content = (
    <>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-medium text-pmai-muted">{label}</p>
        {icon && <span className="text-lg opacity-70">{icon}</span>}
      </div>
      <p className={`mt-1 text-2xl font-bold tabular-nums ${color}`}>{value}</p>
    </>
  );

  if (onClick) {
    return (
      <button type="button" onClick={onClick} className={`${baseClass} ${borderClass} w-full text-right`}>
        {content}
      </button>
    );
  }

  return <div className={`${baseClass} ${borderClass}`}>{content}</div>;
}
