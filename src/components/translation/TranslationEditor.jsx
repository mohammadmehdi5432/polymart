import { HiSparkles } from '../ui/icons';

function FieldPair({
  label,
  valueFa,
  valueEn,
  onChangeEn,
  multiline = false,
  rows = 4,
  targetLabel = 'انگلیسی',
}) {
  const inputClass =
    'w-full rounded border border-pmai-border px-3 py-2 text-sm focus:border-pmai-primary focus:outline-none focus:ring-1 focus:ring-pmai-primary';

  return (
    <div className="rounded border border-pmai-border bg-white p-4">
      <h4 className="mb-3 font-medium text-gray-900">{label}</h4>
      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <p className="mb-1 text-xs font-medium text-pmai-muted">فارسی (منبع)</p>
          {multiline ? (
            <div
              className="min-h-[80px] rounded border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700"
              dangerouslySetInnerHTML={{ __html: valueFa || '—' }}
            />
          ) : (
            <div className="rounded border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {valueFa || '—'}
            </div>
          )}
        </div>
        <div>
          <p className="mb-1 text-xs font-medium text-pmai-muted">{targetLabel} (ترجمه)</p>
          {multiline ? (
            <textarea
              rows={rows}
              value={valueEn ?? ''}
              onChange={(e) => onChangeEn(e.target.value)}
              className={inputClass}
              dir="ltr"
            />
          ) : (
            <input
              type="text"
              value={valueEn ?? ''}
              onChange={(e) => onChangeEn(e.target.value)}
              className={inputClass}
              dir="ltr"
            />
          )}
        </div>
      </div>
    </div>
  );
}

export default function TranslationEditor({
  item,
  saving,
  generating,
  onChange,
  onSave,
  onGenerate,
  onClose,
  embedded = false,
}) {
  if (!item) {
    return null;
  }

  const targetLabel = item.lang_label || 'انگلیسی';

  const updateCustomField = (metaKey, value) => {
    onChange({
      ...item,
      custom_fields: item.custom_fields.map((field) =>
        field.meta_key === metaKey ? { ...field, value_en: value } : field
      ),
    });
  };

  const handleSave = () => {
    const customFields = {};

    item.custom_fields.forEach((field) => {
      customFields[field.meta_key] = field.value_en ?? '';
    });

    onSave({
      title_en: item.title_en ?? '',
      excerpt_en: item.excerpt_en ?? '',
      content_en: item.content_en ?? '',
      custom_fields: customFields,
    });
  };

  return (
    <div
      className={
        embedded
          ? ''
          : 'mt-6 rounded-lg border-2 border-pmai-primary/30 bg-pmai-surface p-5 shadow-sm'
      }
    >
      {!embedded && (
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs text-pmai-muted">
              {item.post_type_label} #{item.post_id} — {targetLabel}
            </p>
            <h3 className="text-lg font-semibold text-gray-900">{item.title_fa}</h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-pmai-border px-3 py-1 text-sm text-gray-600 hover:bg-gray-50"
          >
            بستن
          </button>
        </div>
      )}

      {embedded && (
        <div className="mb-4">
          <p className="text-xs text-pmai-muted">
            {item.post_type_label} #{item.post_id} — {targetLabel}
          </p>
        </div>
      )}

      <div className="space-y-4">
        <FieldPair
          label="عنوان"
          valueFa={item.title_fa}
          valueEn={item.title_en}
          onChangeEn={(value) => onChange({ ...item, title_en: value })}
          targetLabel={targetLabel}
        />

        {item.excerpt_fa && (
          <FieldPair
            label="خلاصه"
            valueFa={item.excerpt_fa}
            valueEn={item.excerpt_en}
            onChangeEn={(value) => onChange({ ...item, excerpt_en: value })}
            multiline
            rows={3}
            targetLabel={targetLabel}
          />
        )}

        {item.content_fa && (
          <FieldPair
            label="محتوا"
            valueFa={item.content_fa}
            valueEn={item.content_en}
            onChangeEn={(value) => onChange({ ...item, content_en: value })}
            multiline
            rows={8}
            targetLabel={targetLabel}
          />
        )}

        {item.custom_fields.map((field) => (
          <FieldPair
            key={field.meta_key}
            label={field.label}
            valueFa={field.value_fa}
            valueEn={field.value_en}
            onChangeEn={(value) => updateCustomField(field.meta_key, value)}
            multiline={field.value_fa.length > 80}
            targetLabel={targetLabel}
          />
        ))}
      </div>

      <div className="mt-6 flex flex-wrap gap-3 border-t border-pmai-border pt-4">
        <button
          type="button"
          onClick={handleSave}
          disabled={saving || generating}
          className="rounded bg-pmai-primary px-4 py-2 text-sm font-medium text-white hover:bg-pmai-primary-dark disabled:opacity-60"
        >
          {saving ? 'در حال ذخیره…' : 'ذخیره ترجمه دستی'}
        </button>
        <button
          type="button"
          onClick={onGenerate}
          disabled={saving || generating}
          className="inline-flex items-center gap-2 rounded border border-pmai-primary bg-white px-4 py-2 text-sm font-medium text-pmai-primary hover:bg-blue-50 disabled:opacity-60"
        >
          <HiSparkles className="h-4 w-4" />
          {generating ? 'در حال تولید…' : 'تولید با هوش مصنوعی'}
        </button>
        {item.edit_url && (
          <a
            href={item.edit_url}
            target="_blank"
            rel="noreferrer"
            className="rounded-lg border border-pmai-border px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50"
          >
            ویرایش در وردپرس
          </a>
        )}
        {item.view_url_fa && (
          <a
            href={item.view_url_fa}
            target="_blank"
            rel="noreferrer"
            className="rounded-lg border border-pmai-border px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50"
          >
            مشاهده فارسی
          </a>
        )}
        {item.view_url_en && (
          <a
            href={item.view_url_en}
            target="_blank"
            rel="noreferrer"
            className="rounded-lg border border-pmai-border px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50"
            dir="ltr"
          >
            مشاهده {item.lang?.toUpperCase() || 'EN'}
          </a>
        )}
      </div>
    </div>
  );
}
