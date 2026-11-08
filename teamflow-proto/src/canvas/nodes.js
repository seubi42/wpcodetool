/** Calque des cartes et sections : création et mise à jour du DOM. */
import { COLORS } from '../core/constants.js';
import { el } from '../ui/dom.js';
import { I } from '../ui/icons.js';
import { blocksHTML, refreshMentions } from '../blocks/render.js';
import { cardSummary } from '../blocks/model.js';

/** DOM d'une carte, partagé par le canevas et la vue plein écran. */
export function createCardEl(n) {
  const e = el('div', 'tf-node tf-card');
  e.innerHTML =
    '<div class="tf-card-tools">' +
    `<button class="tf-ct tf-ct-title" data-card-act="title" title="Ajouter un titre">${I.title}</button>` +
    `<button class="tf-ct tf-ct-collapse" data-card-act="collapse" title="Replier / déplier">${I.chevron}</button>` +
    `<button class="tf-ct" data-card-act="focus" title="Ouvrir en plein écran">${I.expand}</button>` +
    '</div>' +
    `<div class="tf-card-head"><span class="tf-board-icon">${I.board}</span>` +
    '<div class="tf-card-title" data-placeholder="Titre de la carte"></div><div class="tf-card-summary"></div></div>' +
    '<div class="tf-card-body"></div>' +
    '<div class="tf-card-foot"></div>' +
    '<div class="tf-handle" data-side="t"></div><div class="tf-handle" data-side="r"></div>' +
    '<div class="tf-handle" data-side="b"></div><div class="tf-handle" data-side="l"></div>' +
    '<div class="tf-resize tf-resize-w"></div>';
  e._type = 'card';
  e.dataset.id = n.id;
  return e;
}

/**
 * Met à jour le contenu d'une carte (hors édition).
 * @param {{assetUrl(id):string, mentionLabel(id):string|null, childCount(boardId):number}} ctx
 */
export function renderCardContent(e, n, ctx, force) {
  e.dataset.color = n.color || 'default';
  e.style.setProperty('--c', COLORS[n.color] || 'var(--tf-neutral)');
  e.classList.toggle('tf-is-board', !!n.childBoardId);
  e.classList.toggle('tf-no-title', !n.title);
  const t = e.querySelector('.tf-card-title');
  if (t.textContent !== (n.title || '')) t.textContent = n.title || '';
  const body = e.querySelector('.tf-card-body');
  const key = JSON.stringify(n.blocks || []);
  if (force || e._bkey !== key) {
    body.innerHTML = blocksHTML(n.blocks, ctx);
    e._bkey = key;
  }
  refreshMentions(body, ctx);
  e.classList.toggle('tf-empty-body', !(n.blocks && n.blocks.length));
  e.querySelector('.tf-card-summary').textContent = n.title ? '' : cardSummary(n);
  const foot = e.querySelector('.tf-card-foot');
  let html = '';
  if (n.childBoardId) {
    const count = ctx.childCount(n.childBoardId);
    html = `<button class="tf-open" title="Ouvrir le sous-whiteboard">${I.open}<span>Ouvrir</span></button>` +
      `<span class="tf-count">${count} carte${count > 1 ? 's' : ''}</span>`;
  }
  if (foot._html !== html) { foot.innerHTML = html; foot._html = html; }
}

export class NodeLayer {
  constructor(app, sectionsLayer, cardsLayer) {
    this.app = app;
    this.sectionsLayer = sectionsLayer;
    this.cardsLayer = cardsLayer;
    this.els = new Map();
  }

  get(id) { return this.els.get(id); }

  clear() {
    this.sectionsLayer.innerHTML = '';
    this.cardsLayer.innerHTML = '';
    this.els.clear();
  }

  /** Aligne le DOM sur le whiteboard courant. */
  sync() {
    const board = this.app.board, seen = new Set();
    for (const n of board.nodes) {
      seen.add(n.id);
      let e = this.els.get(n.id);
      if (!e || e._type !== n.type) {
        if (e) e.remove();
        e = n.type === 'section' ? this._createSection(n) : createCardEl(n);
        this.els.set(n.id, e);
      }
      this.update(n, e);
    }
    for (const [id, e] of this.els) if (!seen.has(id)) { e.remove(); this.els.delete(id); }
    // ordre DOM = ordre du tableau (plan d'affichage)
    for (const n of board.nodes) {
      const e = this.els.get(n.id), layer = n.type === 'section' ? this.sectionsLayer : this.cardsLayer;
      if (e.parentNode !== layer || e !== layer.lastElementChild) layer.appendChild(e);
    }
  }

  update(n, e = this.els.get(n.id)) {
    if (!e) return;
    this.setGeometry(n, e);
    e.classList.toggle('tf-selected', this.app.selection.has(n.id));
    if (n.type === 'section') {
      e.dataset.color = n.color || 'default';
      e.style.setProperty('--c', COLORS[n.color] || 'var(--tf-neutral)');
      const lab = e.querySelector('.tf-section-label');
      if (document.activeElement !== lab && lab.textContent !== n.title) lab.textContent = n.title;
      return;
    }
    const editing = this.app.editor.isEditingIn(e);
    e.classList.toggle('tf-collapsed', !!n.collapsed && !editing);
    if (!editing) renderCardContent(e, n, this.app.renderCtx);
  }

  /** Force le rendu des blocs d'une carte (après un changement de structure). */
  refresh(n) {
    const e = this.els.get(n.id);
    if (e) { renderCardContent(e, n, this.app.renderCtx, true); this.update(n, e); }
  }

  setGeometry(n, e = this.els.get(n.id)) {
    if (!e) return;
    e.style.left = n.x + 'px';
    e.style.top = n.y + 'px';
    e.style.width = n.w + 'px';
    if (n.type === 'section') e.style.height = n.h + 'px';
  }

  bringToFront(n) {
    const e = this.els.get(n.id);
    if (e && e.parentNode) e.parentNode.appendChild(e);
  }

  /** Rectangle monde d'un nœud (hauteur réelle pour les cartes). */
  rect(n) {
    if (n.type === 'section') return { x: n.x, y: n.y, w: n.w, h: n.h };
    const e = this.els.get(n.id);
    return { x: n.x, y: n.y, w: n.w, h: e ? e.offsetHeight : 80 };
  }

  _createSection(n) {
    const e = el('div', 'tf-node tf-section');
    e.innerHTML = '<div class="tf-section-title"><span class="tf-section-label"></span></div><div class="tf-resize tf-resize-both"></div>';
    e._type = 'section';
    e.dataset.id = n.id;
    return e;
  }
}
