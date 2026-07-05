import api from './settings';

export async function fetchLogs(params = {}) {
  const { data } = await api.get('/logs', { params });
  return data;
}

export async function clearLogs() {
  const { data } = await api.delete('/logs');
  return data;
}
