import api from './settings';

export async function fetchDashboard() {
  const { data } = await api.get('/dashboard');
  return data;
}

export async function testConnection(credentials = {}) {
  const { data } = await api.post('/settings/test-connection', credentials);
  return data;
}

/**
 * Drain runtime-string and async post/term translation queues.
 *
 * @param {number} passes Max runtime-string batches (1–50).
 * @returns {Promise<object>}
 */
export async function processBackgroundQueues(passes = 15) {
  const { data } = await api.post('/system/process-queues', { passes });
  return data;
}

/**
 * Align legacy WVE variation translation meta with the canonical storage layout.
 *
 * @param {object} options
 * @param {boolean} [options.dryRun=false]
 * @param {string} [options.lang='']
 * @param {boolean} [options.all=true]
 * @returns {Promise<object>}
 */
export async function resyncVariationTranslations(options = {}) {
  const { data } = await api.post('/system/resync-variation-translations', {
    dry_run: Boolean(options.dryRun),
    lang: options.lang ?? '',
    all: options.all !== false,
  });

  return data;
}
