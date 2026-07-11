import api from './settings';

/** Max wait for one worker tick (multi-step cron budget + buffer). */
export const JOB_STEP_TIMEOUT_MS = 180000;

const JOB_FETCH_TIMEOUT_MS = 90000;
/** Start/resume bootstrap runs the first worker tick inline. */
const JOB_BOOTSTRAP_TIMEOUT_MS = 180000;
const JOB_FETCH_RETRIES = 4;
const JOB_FETCH_RETRY_DELAY_MS = 1500;
const JOB_STEP_RETRIES = 1;
const JOB_STEP_RETRY_DELAY_MS = 2000;

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

      const isTimeout =
        error?.code === 'ECONNABORTED' || /timeout/i.test(error?.message || '');

      if (isTimeout) {
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

/** Abort an in-flight worker tick (e.g. when user clicks stop). */
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

export async function jobAction(action, lang = 'en', extra = {}) {
  abortJobStep();
  const bootstrapActions = new Set(['start', 'resume', 'ensure', 'kick']);
  const timeout = bootstrapActions.has(action) ? JOB_BOOTSTRAP_TIMEOUT_MS : JOB_FETCH_TIMEOUT_MS;
  const { data } = await withRetries(() =>
    api.post('/translation-job', { action, lang, ...extra }, { timeout })
  );
  return data;
}

/**
 * Run the same multi-step worker tick as WP-Cron.
 * Browser and cron share one pipeline + lock.
 */
export async function jobStep() {
  abortJobStep();
  activeStepController = new AbortController();

  try {
    const { data } = await withRetries(
      () =>
        api.post(
          '/translation-job',
          { action: 'kick' },
          {
            timeout: JOB_STEP_TIMEOUT_MS,
            signal: activeStepController.signal,
          }
        ),
      { retries: JOB_STEP_RETRIES, delayMs: JOB_STEP_RETRY_DELAY_MS }
    );
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

/** Quick ArvanCloud translation smoke test (same code path as bulk jobs). */
export async function testTranslationApi({ text = 'سلام دنیا', lang = 'en' } = {}) {
  const { data } = await api.post(
    '/translation-job/test-api',
    { text, lang },
    { timeout: 150000 }
  );
  return data;
}
