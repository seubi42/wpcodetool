/** Habillage fixe de l'application : barre du haut, contrôles en bas à droite, aide, notifications. */
import { el } from './dom.js';
import { I } from './icons.js';
import { esc } from '../core/util.js';

const btn = (act, icon, label, title) =>
  `<button class="tf-btn" data-act="${act}"${title ? ` title="${esc(title)}"` : ''}>${icon}${label ? `<span>${esc(label)}</span>` : ''}</button>`;

export function buildChrome(root) {
  const top = el('div', 'tf-ui tf-topbar');
  top.innerHTML =
    `<div class="tf-brand"><span class="tf-logo">${I.board}</span>TeamFlow</div>` +
    '<nav class="tf-crumbs"></nav><div class="tf-spacer"></div><span class="tf-status"></span>' +
    '<div class="tf-group">' +
    btn('add-card', I.card, 'Carte', 'Nouvelle carte') +
    btn('add-section', I.section, 'Section', 'Nouvelle section') +
    btn('add-board', I.board, 'Whiteboard', 'Nouvelle carte sous-whiteboard') +
    '</div><div class="tf-group">' +
    btn('undo', I.undo, '', 'Annuler (Ctrl+Z)') + btn('redo', I.redo, '', 'Rétablir (Ctrl+Y)') +
    '</div><div class="tf-group">' +
    btn('export', I.download, '', 'Exporter en JSON') + btn('import', I.upload, '', 'Importer un JSON') +
    btn('reset', I.reset, '', 'Réinitialiser la démo') +
    '</div>';
  root.appendChild(top);

  const br = el('div', 'tf-ui tf-br');
  const minimapWrap = el('div', 'tf-minimap');
  br.appendChild(minimapWrap);
  const ctr = el('div', 'tf-controls');
  ctr.innerHTML =
    btn('toggle-theme', '', '', '') + btn('toggle-map', I.map, '', 'Afficher / masquer la vue d\'ensemble') +
    btn('toggle-mode', '', '', '') + '<i class="tf-sep"></i>' +
    btn('zoom-out', I.minus, '', 'Dézoomer (-)') +
    '<button class="tf-btn tf-zoom" data-act="zoom-reset" title="Revenir à 100 % (0)">100%</button>' +
    btn('zoom-in', I.plus, '', 'Zoomer (+)') + btn('fit', I.fit, '', 'Tout afficher (Maj+1)');
  br.appendChild(ctr);
  root.appendChild(br);

  const hint = el('div', 'tf-ui tf-hint');
  root.appendChild(hint);
  const toast = el('div', 'tf-toast');
  root.appendChild(toast);

  const fileImport = el('input');
  fileImport.type = 'file'; fileImport.accept = 'application/json,.json'; fileImport.hidden = true;
  const fileImage = el('input');
  fileImage.type = 'file'; fileImage.accept = 'image/*'; fileImage.hidden = true;
  root.appendChild(fileImport);
  root.appendChild(fileImage);

  return {
    crumbs: top.querySelector('.tf-crumbs'),
    status: top.querySelector('.tf-status'),
    minimapWrap,
    zoomLabel: ctr.querySelector('.tf-zoom'),
    modeBtn: ctr.querySelector('[data-act="toggle-mode"]'),
    mapBtn: ctr.querySelector('[data-act="toggle-map"]'),
    themeBtn: ctr.querySelector('[data-act="toggle-theme"]'),
    hint, toast, fileImport, fileImage
  };
}
