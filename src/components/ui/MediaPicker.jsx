import { HiPhoto, HiTrash } from './icons';
import { useMediaPicker } from '../../hooks/useMediaPicker';

export default function MediaPicker({ attachmentId, imageUrl, onChange, label = 'پرچم' }) {
  const { openPicker } = useMediaPicker();

  const handleSelect = () => {
    openPicker(({ id, url }) => {
      onChange(id, url);
    }, { title: `انتخاب ${label}` });
  };

  return (
    <div className="flex items-center gap-3">
      <button
        type="button"
        onClick={handleSelect}
        className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-pmai-border bg-gray-50 transition hover:border-pmai-primary hover:bg-blue-50"
        title={`انتخاب ${label} از رسانه`}
      >
        {imageUrl ? (
          <img src={imageUrl} alt="" className="h-full w-full object-cover" />
        ) : (
          <HiPhoto className="h-5 w-5 text-pmai-muted" />
        )}
      </button>

      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-gray-900">{label}</p>
        <p className="text-xs text-pmai-muted">
          {attachmentId ? `شناسه رسانه: ${attachmentId}` : 'از کتابخانه رسانه وردپرس انتخاب کنید'}
        </p>
      </div>

      {attachmentId > 0 && (
        <button
          type="button"
          onClick={() => onChange(0, '')}
          className="rounded-lg border border-red-200 p-2 text-red-600 transition hover:bg-red-50"
          title="حذف پرچم"
        >
          <HiTrash className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}
