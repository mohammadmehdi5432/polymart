import { api } from './settings';

export async function fetchCurrencyStatus() {
  const { data } = await api.get('/currency/status');
  return data;
}

export async function refreshCurrencyRate() {
  const { data } = await api.post('/currency/refresh');
  return data;
}

export async function fetchSyncJob() {
  const { data } = await api.get('/currency/sync-job');
  return data;
}

export async function syncJobAction(action) {
  const { data } = await api.post('/currency/sync-job', { action });
  return data;
}

export async function syncJobStep() {
  const { data } = await api.post('/currency/sync-job/step', null, { timeout: SYNC_STEP_TIMEOUT_MS });
  return data;
}

export const SYNC_STEP_TIMEOUT_MS = 120000;
