import { useState } from 'react';
import { HiClipboardDocument } from './icons';

export default function CopyButton({ text, label = 'کپی', className = '' }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }
  };

  return (
    <button
      type="button"
      onClick={handleCopy}
      className={`inline-flex items-center gap-1 rounded-lg border border-pmai-border bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 ${className}`}
    >
      <HiClipboardDocument className="h-3.5 w-3.5" />
      {copied ? 'کپی شد!' : label}
    </button>
  );
}
