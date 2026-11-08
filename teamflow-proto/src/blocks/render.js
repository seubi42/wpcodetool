/** Rendu HTML des blocs d'une carte. */
import { esc } from '../core/util.js';
import { BLOCK_TYPES } from './types.js';

/**
 * @param {object} b bloc
 * @param {number} num numéro (listes numérotées)
 * @param {{assetUrl(id:string):string}} ctx
 */
export function blockHTML(b, num, ctx) {
  const d = BLOCK_TYPES[b.type] || BLOCK_TYPES.text;
  const cls = 'tf-block tf-b-' + b.type + (b.checked ? ' tf-checked' : '') + (b.open ? ' tf-open-t' : '');
  let h = `<div class="${cls}" data-bid="${b.id}"${b.indent ? ` style="--ind:${b.indent}"` : ''}>` +
    '<span class="tf-grip" title="Glisser pour déplacer · cliquer pour les options"></span>';
  const bc = () => `<div class="tf-bc" data-ph="${esc(d.ph || '')}">${b.html || ''}</div>`;
  switch (b.type) {
    case 'bullet': h += '<span class="tf-mark tf-bullet"></span>' + bc(); break;
    case 'number': h += `<span class="tf-mark tf-num">${num}.</span>` + bc(); break;
    case 'todo': h += '<span class="tf-mark tf-check" title="Cocher"></span>' + bc(); break;
    case 'toggle':
      h += '<span class="tf-mark tf-tog" title="Déplier / replier"></span><div class="tf-tog-wrap">' + bc() +
        `<div class="tf-bc tf-tog-body" data-ph="Contenu caché">${b.body || ''}</div></div>`;
      break;
    case 'callout': h += '<span class="tf-mark tf-callout-ic">💡</span>' + bc(); break;
    case 'code': h += `<pre class="tf-bc tf-code" data-ph="Code">${esc(b.text || '')}</pre>`; break;
    case 'divider': h += '<hr>'; break;
    case 'image': {
      const src = b.asset ? ctx.assetUrl(b.asset) : (b.src || '');
      h += `<img src="${esc(src)}" alt="" draggable="false">`;
      break;
    }
    default: h += bc();
  }
  return h + '</div>';
}

/** Rend tous les blocs d'une carte (numérotation des listes comprise). */
export function blocksHTML(blocks, ctx) {
  const counters = {};
  let html = '';
  for (const b of blocks || []) {
    const k = b.indent || 0;
    let num = 0;
    for (const l of Object.keys(counters)) if (+l > k) delete counters[l];
    if (b.type === 'number') { counters[k] = (counters[k] || 0) + 1; num = counters[k]; }
    else delete counters[k];
    html += blockHTML(b, num, ctx);
  }
  return html;
}

/** Met à jour le libellé des liens vers des cartes (titre courant de la cible). */
export function refreshMentions(root, ctx) {
  root.querySelectorAll('.tf-mention').forEach((m) => {
    const label = ctx.mentionLabel(m.dataset.card);
    m.textContent = label || 'carte supprimée';
    m.classList.toggle('tf-broken', !label);
    m.contentEditable = 'false';
    m.title = label ? 'Aller à la carte « ' + label + ' »' : 'Cette carte n\'existe plus';
  });
}
