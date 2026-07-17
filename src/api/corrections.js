import { api } from './settings';

export function previewCorrections(payload) {
  return api.post('/corrections/preview', payload, { timeout: 120000 }).then((r) => r.data);
}

export function applyCorrections(payload) {
  return api.post('/corrections/apply', payload, { timeout: 60000 }).then((r) => r.data);
}

export function fetchCorrectionsJob() {
  return api.get('/corrections/job').then((r) => r.data);
}

export function stepCorrectionsJob(batch = 15) {
  return api.post('/corrections/job/step', { batch }, { timeout: 180000 }).then((r) => r.data);
}

export function clearCorrectionsJob() {
  return api.delete('/corrections/job').then((r) => r.data);
}

export function fetchGlossary(lang = '') {
  const params = lang ? { lang } : {};
  return api.get('/corrections/glossary', { params }).then((r) => r.data);
}

export function upsertGlossaryEntry({ lang, wrong, preferred, match = 'exact' }) {
  return api
    .put('/corrections/glossary', { action: 'upsert', lang, wrong, preferred, match })
    .then((r) => r.data);
}

export function deleteGlossaryEntry({ lang, wrong }) {
  return api
    .put('/corrections/glossary', { action: 'delete', lang, wrong })
    .then((r) => r.data);
}
