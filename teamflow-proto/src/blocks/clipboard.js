/**
 * Copier / coller de blocs entiers.
 * Vers l'extérieur : texte (façon Markdown) et HTML simple.
 * Entre cartes TeamFlow : les blocs exacts (cases cochées, images, liens @…),
 * reconnus grâce au texte copié.
 */
import { esc, clone, uid } from '../core/util.js';
import { blockText } from './model.js';

const TEXT_PREFIX = { h1: '# ', h2: '## ', h3: '### ', bullet: '- ', quote: '> ', toggle: '▸ ' };
const HTML_TAG = { h1: 'h1', h2: 'h2', h3: 'h3', quote: 'blockquote' };

let internal = null; // { text, blocks }

/** Texte brut lisible partout (préfixes Markdown). */
export function blocksToText(blocks) {
  const out = [];
  let num = 0;
  for (const b of blocks) {
    const pad = '  '.repeat(b.indent || 0);
    num = b.type === 'number' ? num + 1 : 0;
    if (b.type === 'code') out.push('```\n' + (b.text || '') + '\n```');
    else if (b.type === 'divider') out.push('---');
    else if (b.type === 'image') out.push('[image]');
    else if (b.type === 'todo') out.push(pad + (b.checked ? '[x] ' : '[ ] ') + blockText(b));
    else if (b.type === 'number') out.push(pad + num + '. ' + blockText(b));
    else out.push(pad + (TEXT_PREFIX[b.type] || '') + blockText(b));
  }
  return out.join('\n');
}

/** HTML sémantique simple, pour coller dans un traitement de texte ou un mail. */
export function blocksToHTML(blocks, assetUrl) {
  let html = '', list = null;
  const close = () => { if (list) { html += `</${list}>`; list = null; } };
  for (const b of blocks) {
    const want = b.type === 'bullet' || b.type === 'todo' ? 'ul' : b.type === 'number' ? 'ol' : null;
    if (want !== list) { close(); if (want) { html += `<${want}>`; list = want; } }
    const inner = b.html || '';
    if (want) html += `<li>${b.type === 'todo' ? (b.checked ? '☑ ' : '☐ ') : ''}${inner}</li>`;
    else if (b.type === 'code') html += `<pre><code>${esc(b.text || '')}</code></pre>`;
    else if (b.type === 'divider') html += '<hr>';
    else if (b.type === 'image') { const src = b.asset ? assetUrl(b.asset) : b.src; if (src) html += `<p><img src="${esc(src)}"></p>`; }
    else { const tag = HTML_TAG[b.type] || 'p'; html += `<${tag}>${inner}</${tag}>`; }
  }
  close();
  return html;
}

/** Mémorise les blocs copiés et remplit le presse-papiers de l'événement. */
export function writeBlocks(clipboardData, blocks, assetUrl) {
  const text = blocksToText(blocks);
  internal = { text: norm(text), blocks: clone(blocks) };
  clipboardData.setData('text/plain', text);
  clipboardData.setData('text/html', blocksToHTML(blocks, assetUrl));
}

/** Blocs TeamFlow correspondant au texte collé (copies avec de nouveaux id), sinon null. */
export function readBlocks(text) {
  if (!internal || norm(text) !== internal.text) return null;
  return clone(internal.blocks).map((b) => Object.assign(b, { id: uid('k') }));
}

const norm = (t) => (t || '').replace(/\r/g, '').trim();
