/** Petits utilitaires sans dépendance au DOM de l'application. */

export function uid(prefix) {
  return prefix + '_' + Math.random().toString(36).slice(2, 10);
}

export function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ESC[c]);
}

export function clone(o) {
  return JSON.parse(JSON.stringify(o));
}

export function debounce(fn, ms) {
  let t = null;
  const d = (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
  d.cancel = () => clearTimeout(t);
  return d;
}

/** Normalise un texte pour la recherche (minuscules, sans accents). */
export function norm(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/** Préférences locales de l'utilisateur (thème, mode de navigation…). */
export const prefs = {
  get(key) {
    try { return localStorage.getItem('teamflow:pref:' + key); } catch (e) { return null; }
  },
  set(key, value) {
    try { localStorage.setItem('teamflow:pref:' + key, value); } catch (e) { /* stockage indisponible */ }
  }
};
