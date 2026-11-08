/** Stockage dans le navigateur (localStorage). */
export function localStorageAdapter(key) {
  return {
    async load() {
      try {
        const s = localStorage.getItem(key);
        return s ? JSON.parse(s) : null;
      } catch (e) {
        return null;
      }
    },
    async save(doc) {
      localStorage.setItem(key, JSON.stringify(doc));
    }
  };
}
