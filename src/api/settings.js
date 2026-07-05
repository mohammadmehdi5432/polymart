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
