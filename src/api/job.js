import api from './settings';

/** Max wait for one translation step (chunked steps should finish well under this). */
export const JOB_STEP_TIMEOUT_MS = 240000;

const JOB_FETCH_TIMEOUT_MS = 90000;
const JOB_FETCH_RETRIES = 4;
const JOB_FETCH_RETRY_DELAY_MS = 1500;

let activeStepController = null;

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

      if (error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError') {
        throw error;
      }

      if (attempt >= retries) {
        break;
      }

      await sleep(delayMs * (attempt + 1));
    }
  }

  throw lastError;
}

/** Abort an in-flight translation step request (e.g. when user clicks stop). */
export function abortJobStep() {
  if (activeStepController) {
    activeStepController.abort();
    activeStepController = null;
  }
}

export async function fetchJob() {
  const { data } = await withRetries(() =>
    api.get('/translation-job', { timeout: JOB_FETCH_TIMEOUT_MS })
  );
  return data;
}

export async function jobAction(action, lang = 'en') {
  abortJobStep();
  const { data } = await withRetries(() =>
    api.post('/translation-job', { action, lang }, { timeout: JOB_FETCH_TIMEOUT_MS })
  );
  return data;
}

export async function jobStep() {
  abortJobStep();
  activeStepController = new AbortController();

  try {
    const { data } = await api.post('/translation-job/step', null, {
      timeout: JOB_STEP_TIMEOUT_MS,
      signal: activeStepController.signal,
    });
    return data;
  } catch (error) {
    const isAbort = error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError';

    if (isAbort) {
      const job = await fetchJob().catch(() => null);
      if (job) {
        return { ...job, step_aborted: true };
      }

      throw error;
    }

    const isTimeout =
      error?.code === 'ECONNABORTED' || /timeout/i.test(error?.message || '');

    if (!isTimeout) {
      throw error;
    }

    const job = await fetchJob();

    if (job?.status === 'running' || job?.status === 'paused') {
      return {
        ...job,
        step_recovered_after_timeout: true,
      };
    }

    throw error;
  } finally {
    activeStepController = null;
  }
}

export async function refreshJobStats(lang = 'en') {
  const { data } = await withRetries(() =>
    api.post('/translation-job', { action: 'refresh_stats', lang }, { timeout: 120000 })
  );
  return data;
}
