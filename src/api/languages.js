import api from './settings';

export async function fetchLanguages() {
  const { data } = await api.get('/languages');
  return data;
}

export async function saveLanguages(languages) {
  const { data } = await api.post('/languages', { languages });
  return data;
}

export async function fetchLanguagePresets() {
  const { data } = await api.get('/languages/presets');
  return data;
}
