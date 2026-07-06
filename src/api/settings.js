import axios from 'axios';

const config = window.polymartAiSettings ?? {};

export const api = axios.create({
  baseURL: config.apiUrl ?? '/wp-json/polymart/v1',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': config.nonce ?? '',
  },
});

/** Shared timeout for AI-heavy REST calls (Elementor pages, bulk translate). */
export const AI_REQUEST_TIMEOUT_MS = 360000;

let nonceRefreshPromise = null;

/** Fetch a fresh wp_rest nonce (admin session may outlive the localized one). */
export async function refreshRestNonce() {
  if (!nonceRefreshPromise) {
    nonceRefreshPromise = api
      .get('/rest-nonce', { timeout: 30000, _skipNonceRetry: true })
      .then(({ data }) => {
        const next = data?.nonce ?? '';

        if (next) {
          api.defaults.headers['X-WP-Nonce'] = next;

          if (window.polymartAiSettings) {
            window.polymartAiSettings.nonce = next;
          }
        }

        return next;
      })
      .finally(() => {
        nonceRefreshPromise = null;
      });
  }

  return nonceRefreshPromise;
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error?.response?.status;
    const original = error?.config;
    const isNonceRetry = original?._nonceRetried;

    if (status === 403 && original && !original._skipNonceRetry && !isNonceRetry) {
      try {
        await refreshRestNonce();
        original._nonceRetried = true;
        return api.request(original);
      } catch {
        // Fall through to the caller.
      }
    }

    return Promise.reject(error);
  }
);

api.interceptors.response.use((response) => {
  if (typeof response.data === 'string') {
    const raw = response.data.trim();

    if (raw.startsWith('{') || raw.startsWith('[')) {
      try {
        response.data = JSON.parse(raw);
      } catch {
        // Keep the original response so callers can show raw diagnostics.
      }
    }
  }

  return response;
});

export async function fetchSettings() {
  const { data } = await api.get('/settings');
  return data;
}

export async function saveSettings(settings) {
  const { data } = await api.post('/settings', settings);
  return data;
}

export default api;
