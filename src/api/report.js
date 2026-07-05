import api from './settings';

export async function fetchReport(params = {}) {
  const { data } = await api.get('/translation-report', { params });
  return data;
}
