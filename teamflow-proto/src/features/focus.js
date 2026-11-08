/** Vue plein écran d'une carte : on y édite son contenu comme un document, puis on revient au schéma. */
import { el } from '../ui/dom.js';
import { I } from '../ui/icons.js';
import { esc } from '../core/util.js';
import { createCardEl, renderCardContent } from '../canvas/nodes.js';
import { COLORS } from '../core/constants.js';

const SPECIALS = '.tf-grip,.tf-check,.tf-tog,.tf-mention';

export class FocusView {
  constructor(app) {
    this.app = app;
    this.cardId = null;
    this.el = null;
    this.cardEl = null;
    this.scroll = null;
  }

  get isOpen() { return !!this.cardId; }
  isOpenFor(id) { return this.cardId === id; }

  open(cardId) {
    const app = this.app, n = app.node(cardId);
    if (!n || n.type !== 'card') return;
    if (this.isOpen) this.close();
    if (app.editor.active) app.editor.stop();
    this.cardId = cardId;
    this.el = el('div', 'tf-focus');
    this.el.innerHTML =
      '<div class="tf-focus-bar">' +
      `<button class="tf-btn tf-focus-back" title="Revenir au schéma (Échap)">${I.close}<span>Revenir au schéma</span></button>` +
      `<span class="tf-focus-where">${esc(app.boardLabel(app.board))}</span>` +
      '</div><div class="tf-focus-scroll"></div>';
    this.scroll = this.el.querySelector('.tf-focus-scroll');
    this.cardEl = createCardEl(n);
    this.cardEl.classList.add('tf-focus-card');
    this.scroll.appendChild(this.cardEl);
    app.root.appendChild(this.el);
    app.root.classList.add('tf-focus-open');

    this.el.querySelector('.tf-focus-back').addEventListener('click', () => this.close());
    this.el.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });
    this.el.addEventListener('pointerdown', (e) => {
      app.menu.close();
      const sp = e.button === 0 ? e.target.closest(SPECIALS) : null;
      if (sp && this.cardEl.contains(sp)) { e.preventDefault(); app.interactions.handleSpecial(e, sp, this.scroll); return; }
      // clic hors du texte : on garde l'édition et on place le curseur à la fin
      if (!e.target.closest('.tf-card') && !e.target.closest('.tf-focus-bar')) {
        e.preventDefault();
        const bcs = this.cardEl.querySelectorAll('.tf-block > .tf-bc');
        if (bcs.length) app.editor.focusBlock(bcs[bcs.length - 1].closest('.tf-block').dataset.bid, 'end');
      }
    });
    this.render();
    app.editor.start(cardId, { host: this.cardEl });
    app.toolbars.render();
  }

  render() {
    const n = this.app.node(this.cardId);
    if (!n || !this.cardEl) return;
    renderCardContent(this.cardEl, n, this.app.renderCtx, true);
    this.cardEl.style.setProperty('--c', COLORS[n.color] || 'var(--tf-neutral)');
  }

  close() {
    if (!this.isOpen) return;
    const app = this.app, id = this.cardId;
    this.cardId = null;
    if (app.editor.host === this.cardEl) app.editor.stop();
    this.el.remove();
    this.el = this.cardEl = this.scroll = null;
    app.root.classList.remove('tf-focus-open');
    if (app.node(id)) { app.refreshCard(id); app.selection.set([id]); }
    app.render();
  }
}
