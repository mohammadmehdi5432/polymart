import api, { AI_REQUEST_TIMEOUT_MS } from './settings.js';

export async function fetchTranslations(params = {}) {
  const { data } = await api.get('/translations', { params });
  return data;
}

export async function fetchTranslation(postId, lang = 'en') {
  const { data } = await api.get(`/translations/${postId}`, { params: { lang } });
  return data;
}

export async function saveTranslation(postId, fields, lang = 'en') {
  const { data } = await api.put(`/translations/${postId}`, fields, {
    params: { lang },
    timeout: AI_REQUEST_TIMEOUT_MS,
  });
  return data;
}

export async function generateTranslation(postId, lang = 'en') {
  const { data } = await api.post(`/translations/${postId}/generate`, null, {
    params: { lang },
    timeout: AI_REQUEST_TIMEOUT_MS,
  });
  return data;
}
