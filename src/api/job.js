import api from './settings';

/** Max wait for one translation step (chunked steps should finish well under this). */
export const JOB_STEP_TIMEOUT_MS = 180000;

const JOB_FETCH_TIMEOUT_MS = 90000;
const JOB_FETCH_RETRIES = 4;
const JOB_FETCH_RETRY_DELAY_MS = 1500;

async function sleep(ms) {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms);
  });
}

async function withRetries(fn, { retries = JOB_FETCH_RETRIES, delayMs = JOB_FETCH_RETRY_DELAY_MS } = {}) {
  let lastError;

  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      return await fn();
    } catch (error) {
      lastError = error;

      if (attempt >= retries) {
        break;
      }

      await sleep(delayMs * (attempt + 1));
    }
  }

  throw lastError;
}

export async function fetchJob() {
  const { data } = await withRetries(() =>
    api.get('/translation-job', { timeout: JOB_FETCH_TIMEOUT_MS })
  );
  return data;
}

export async function jobAction(action, lang = 'en') {
  const { data } = await withRetries(() =>
    api.post('/translation-job', { action, lang }, { timeout: JOB_FETCH_TIMEOUT_MS })
  );
  return data;
}

export async function jobStep() {
  try {
    const { data } = await api.post('/translation-job/step', null, {
      timeout: JOB_STEP_TIMEOUT_MS,
    });
    return data;
  } catch (error) {
    const isTimeout =
      error?.code === 'ECONNABORTED' || /timeout/i.test(error?.message || '');

    if (!isTimeout) {
      throw error;
    }

    // Step may still have completed server-side — poll lightweight job state.
    const job = await fetchJob();

    if (job?.status === 'running' || job?.status === 'paused') {
      return {
        ...job,
        step_recovered_after_timeout: true,
      };
    }

    throw error;
  }
}

export async function refreshJobStats(lang = 'en') {
  const { data } = await withRetries(() =>
    api.post('/translation-job', { action: 'refresh_stats', lang }, { timeout: 120000 })
  );
  return data;
}
