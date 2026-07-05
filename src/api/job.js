import api from './settings';

/** Max wait for one translation step (Elementor pages can take several minutes). */
export const JOB_STEP_TIMEOUT_MS = 360000;

export async function fetchJob() {
  const { data } = await api.get('/translation-job', { timeout: 60000 });
  return data;
}

export async function jobAction(action, lang = 'en') {
  const { data } = await api.post('/translation-job', { action, lang }, { timeout: 60000 });
  return data;
}

export async function jobStep() {
  const { data } = await api.post('/translation-job/step', null, {
    timeout: JOB_STEP_TIMEOUT_MS,
  });
  return data;
}

export async function refreshJobStats(lang = 'en') {
  const { data } = await api.post('/translation-job', { action: 'refresh_stats', lang }, { timeout: 120000 });
  return data;
}
