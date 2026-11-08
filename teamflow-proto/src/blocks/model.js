/** Opérations sur les données d'un bloc (sans DOM d'application). */
import { esc } from '../core/util.js';
import { BLOCK_TYPES } from './types.js';

export function htmlText(html) {
  const d = document.createElement('div');
  d.innerHTML = html || '';
  return d.textContent;
}

export function blockText(b) {
  return b.type === 'code' ? (b.text || '') : htmlText(b.html);
}

export function textLen(html) {
  return htmlText(html).length;
}

/** Transforme un bloc en un autre type en gardant son contenu. */
export function convertBlock(b, type) {
  if (b.type === type) return;
  if (type === 'code') { b.text = blockText(b); b.html = ''; }
  else if (b.type === 'code') { b.html = esc(b.text || '').replace(/\n/g, '<br>'); delete b.text; }
  if (!BLOCK_TYPES[type].list) delete b.indent;
  if (type !== 'todo') delete b.checked;
  if (type === 'toggle' && b.body == null) { b.body = ''; b.open = true; }
  b.type = type;
}

/** Petit résumé textuel d'une carte (titre, sinon premier texte). */
export function cardSummary(card, max = 60) {
  if (card.title) return card.title;
  for (const b of card.blocks || []) {
    const t = blockText(b).trim();
    if (t) return t.length > max ? t.slice(0, max - 1) + '…' : t;
    if (b.type === 'image') return 'Image';
  }
  return 'Carte sans titre';
}
