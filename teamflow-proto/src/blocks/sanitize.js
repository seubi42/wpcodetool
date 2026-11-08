/** Nettoyage du HTML en ligne des blocs (seules quelques balises de mise en forme sont gardées). */
import { esc } from '../core/util.js';
import { nb } from '../core/model.js';

const INLINE_OK = { B: 1, STRONG: 1, I: 1, EM: 1, S: 1, STRIKE: 1, DEL: 1, U: 1, CODE: 1, A: 1, BR: 1, SPAN: 1 };

function unwrap(n) {
  const frag = document.createDocumentFragment();
  while (n.firstChild) frag.appendChild(n.firstChild);
  n.replaceWith(frag);
}

export function sanitizeInline(html) {
  const t = document.createElement('template');
  t.innerHTML = html || '';
  (function walk(parent) {
    for (const n of Array.from(parent.childNodes)) {
      if (n.nodeType === 8) { n.remove(); continue; }
      if (n.nodeType !== 1) continue;
      // Un lien vers une carte est atomique : on garde seulement son identifiant.
      if (n.tagName === 'SPAN' && n.classList.contains('tf-mention') && n.dataset.card) {
        const id = n.dataset.card;
        for (const a of Array.from(n.attributes)) n.removeAttribute(a.name);
        n.className = 'tf-mention';
        n.dataset.card = id;
        continue;
      }
      walk(n);
      if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|IMG|SVG|TEMPLATE)$/i.test(n.tagName)) { n.remove(); continue; }
      if (!INLINE_OK[n.tagName] || n.tagName === 'SPAN') { unwrap(n); continue; }
      for (const a of Array.from(n.attributes)) {
        if (!(n.tagName === 'A' && a.name === 'href')) n.removeAttribute(a.name);
      }
      if (n.tagName === 'A') {
        if (/^\s*javascript:/i.test(n.getAttribute('href') || '')) n.removeAttribute('href');
        n.setAttribute('target', '_blank');
        n.setAttribute('rel', 'noopener');
      }
    }
  })(t.content);
  return t.innerHTML.replace(/(<br>)+$/, '');
}

/** Convertit l'ancien format (HTML libre, v1) en blocs. */
export function htmlToBlocks(html) {
  const t = document.createElement('template');
  t.innerHTML = html || '';
  const out = [];
  for (const n of Array.from(t.content.childNodes)) {
    if (n.nodeType === 3) { if (n.textContent.trim()) out.push(nb('text', esc(n.textContent.trim()))); continue; }
    if (n.nodeType !== 1) continue;
    const tag = n.tagName;
    if (/^H[1-3]$/.test(tag)) out.push(nb(tag.toLowerCase(), sanitizeInline(n.innerHTML)));
    else if (tag === 'UL' || tag === 'OL') n.querySelectorAll(':scope > li').forEach((li) => out.push(nb(tag === 'UL' ? 'bullet' : 'number', sanitizeInline(li.innerHTML))));
    else if (tag === 'PRE') out.push(nb('code', '', { text: n.textContent }));
    else if (tag === 'IMG') out.push(nb('image', '', { src: n.getAttribute('src') }));
    else if (tag === 'HR') out.push(nb('divider'));
    else if (tag === 'BLOCKQUOTE') out.push(nb('quote', sanitizeInline(n.innerHTML)));
    else {
      n.querySelectorAll('img').forEach((i) => { out.push(nb('image', '', { src: i.getAttribute('src') })); i.remove(); });
      if (n.textContent.trim()) out.push(nb('text', sanitizeInline(n.innerHTML)));
    }
  }
  return out;
}
