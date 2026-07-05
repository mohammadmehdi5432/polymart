import api from './settings';

export async function fetchUiStringsStats(lang = 'en') {
  const { data } = await api.get('/ui-strings', { params: { lang } });
  return data;
}

export async function scanUiStrings() {
  const { data } = await api.post('/ui-strings/scan');
  return data;
}

export async function uiStringsJobAction(action, lang = 'en') {
  const { data } = await api.post('/ui-strings/job', { action, lang });
  return data;
}

export async function uiStringsJobStep() {
  const { data } = await api.post('/ui-strings/job/step', {}, { timeout: 180000 });
  return data;
}

export async function sandboxTestUiStringFile(filePath) {
  const { data } = await api.post('/ui-strings/sandbox-test', { file_path: filePath });
  return data;
}
