import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { HiXMark } from './icons';

export default function Modal({ open, title, onClose, children, wide = false, confirmClose = null }) {
  const closingRef = useRef(false);

  const requestClose = () => {
    if (closingRef.current) {
      return;
    }

    if (typeof confirmClose === 'function') {
      const allowed = confirmClose();

      if (!allowed) {
        return;
      }
    }

    closingRef.current = true;
    onClose();
    window.setTimeout(() => {
      closingRef.current = false;
    }, 0);
  };

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        requestClose();
      }
    };

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, onClose, confirmClose]);

  if (!open) {
    return null;
  }

  const portalTarget = document.getElementById('polymart-ai-root') || document.body;

  return createPortal(
    <div className="fixed inset-0 z-[100000] flex items-start justify-center overflow-y-auto p-4 sm:p-8">
      <button
        type="button"
        className="fixed inset-0 cursor-pointer bg-black/45"
        aria-label="بستن"
        onClick={requestClose}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="pmai-modal-title"
        className={`relative z-10 w-full rounded-xl border border-pmai-border bg-white shadow-2xl ${
          wide ? 'max-w-5xl' : 'max-w-2xl'
        }`}
      >
        <div className="flex items-start justify-between gap-3 border-b border-pmai-border px-5 py-4">
          <h2 id="pmai-modal-title" className="text-lg font-semibold text-gray-900">
            {title}
          </h2>
          <button
            type="button"
            onClick={requestClose}
            className="cursor-pointer rounded-lg p-1 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900"
            aria-label="بستن"
          >
            <HiXMark className="h-5 w-5" />
          </button>
        </div>
        <div className="max-h-[calc(100vh-8rem)] overflow-y-auto px-5 py-4">{children}</div>
      </div>
    </div>,
    portalTarget
  );
}
