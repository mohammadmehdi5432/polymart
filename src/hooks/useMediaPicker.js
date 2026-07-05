import { useCallback } from 'react';

function waitForWpMedia(maxAttempts = 40, intervalMs = 100) {
  return new Promise((resolve, reject) => {
    let attempts = 0;

    const check = () => {
      if (window.wp?.media) {
        resolve(window.wp.media);
        return;
      }

      attempts += 1;
      if (attempts >= maxAttempts) {
        reject(new Error('wp.media unavailable'));
        return;
      }

      window.setTimeout(check, intervalMs);
    };

    check();
  });
}

export function useMediaPicker() {
  const openPicker = useCallback(async (onSelect, options = {}) => {
    const title = options.title || 'انتخاب تصویر';

    try {
      const media = await waitForWpMedia();

      const frame = media({
        title,
        button: { text: 'استفاده از این تصویر' },
        library: { type: 'image' },
        multiple: false,
      });

      frame.on('select', () => {
        const attachment = frame.state().get('selection').first()?.toJSON();

        if (attachment?.id) {
          onSelect({
            id: attachment.id,
            url: attachment.sizes?.thumbnail?.url || attachment.url,
          });
        }
      });

      frame.open();
    } catch {
      // eslint-disable-next-line no-alert
      alert('کتابخانه رسانه وردپرس در دسترس نیست. صفحه را رفرش کنید یا کش مرورگر را پاک کنید.');
    }
  }, []);

  return { openPicker };
}
