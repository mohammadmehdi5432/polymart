import api, { AI_REQUEST_TIMEOUT_MS } from './settings.js';

export async function fetchUntranslated(lang = 'en', afterId = 0) {
  const params = { lang };

  if (afterId > 0) {
    params.after_id = afterId;
  }

  const { data } = await api.get('/untranslated', { params, timeout: 120000 });
  return data;
}

export async function bulkTranslatePost(postId, lang = 'en') {
  const { data } = await api.post(
    '/bulk-translate',
    { post_id: postId, lang },
    { timeout: AI_REQUEST_TIMEOUT_MS }
  );
  return data;
}
