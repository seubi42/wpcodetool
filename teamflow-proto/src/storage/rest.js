/**
 * Stockage via une API REST (prévu pour WordPress : register_rest_route + en-tête X-WP-Nonce).
 * GET url -> JSON du document ; POST url <- JSON du document.
 */
export function restAdapter(url, opts = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (opts.nonce) headers['X-WP-Nonce'] = opts.nonce;
  return {
    async load() {
      const r = await fetch(url, { headers, credentials: 'same-origin' });
      if (r.status === 404) return null;
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    },
    async save(doc) {
      const r = await fetch(url, { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify(doc) });
      if (!r.ok) throw new Error('HTTP ' + r.status);
    }
  };
}
