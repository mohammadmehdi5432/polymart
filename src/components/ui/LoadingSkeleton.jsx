export function SkeletonLine({ className = '' }) {
  return <div className={`animate-pulse rounded bg-gray-200 ${className}`} />;
}

export function SkeletonCard() {
  return (
    <div className="rounded-lg border border-pmai-border bg-white p-4">
      <SkeletonLine className="mb-2 h-3 w-20" />
      <SkeletonLine className="h-8 w-16" />
    </div>
  );
}

export function SkeletonTable({ rows = 5 }) {
  return (
    <div className="space-y-3 p-4">
      {Array.from({ length: rows }).map((_, index) => (
        <SkeletonLine key={index} className="h-10 w-full" />
      ))}
    </div>
  );
}

export function SkeletonFields({ count = 3 }) {
  return (
    <div className="space-y-4 rounded-lg border border-pmai-border bg-pmai-surface p-4">
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="grid gap-2 sm:grid-cols-3">
          <SkeletonLine className="h-4 w-24" />
          <SkeletonLine className="h-10 sm:col-span-2" />
        </div>
      ))}
    </div>
  );
}
