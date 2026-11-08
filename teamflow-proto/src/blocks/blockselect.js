/**
 * Sélection de plusieurs blocs dans la carte en cours d'édition.
 *
 *  - glisser la souris d'un bloc à un autre, ou Maj+clic sur un autre bloc ;
 *  - Maj+↑ / Maj+↓ depuis le bord d'un bloc ;
 *  - Ctrl+A deux fois (le 1er sélectionne le texte du bloc).
 *
 * Ensuite : Suppr / Retour arrière, Ctrl+C / Ctrl+X (puis Ctrl+V dans n'importe quelle carte),
 * Tab / Maj+Tab sur les listes, glisser la poignée pour déplacer tout le groupe, Échap pour annuler.
 */
import { nb } from '../core/model.js';
import { clamp } from '../core/util.js';
import { isList, isVoid } from './types.js';
import { writeBlocks } from './clipboard.js';

export class BlockSelection {
  /** @param {import('./editor.js').CardEditor} editor */
  constructor(editor) {
    this.editor = editor;
    this.app = editor.app;
    this.ids = [];        // ordre du document
    this.anchor = null;   // bloc de départ (pour étendre)
    this.head = null;     // bloc d'arrivée
    const root = this.app.root;
    root.addEventListener('pointerdown', (e) => this._onPointerDown(e));
    root.addEventListener('copy', (e) => this._onCopy(e, false));
    root.addEventListener('cut', (e) => this._onCopy(e, true));
  }

  get active() { return this.ids.length > 0; }
  has(bid) { return this.ids.includes(bid); }

  /** Blocs sélectionnés, dans l'ordre de la carte. */
  blocks() {
    const n = this.editor.card;
    return n ? n.blocks.filter((b) => this.ids.includes(b.id)) : [];
  }

  /** Sélectionne les blocs de `from` à `to` (inclus). */
  select(from, to) {
    const n = this.editor.card;
    if (!n) return;
    const i = n.blocks.findIndex((b) => b.id === from), j = n.blocks.findIndex((b) => b.id === to);
    if (i < 0 || j < 0) return;
    if (!this.active) this._leaveText();
    this.anchor = from;
    this.head = to;
    this.ids = n.blocks.slice(Math.min(i, j), Math.max(i, j) + 1).map((b) => b.id);
    this.paint();
  }

  selectAll() {
    const n = this.editor.card;
    if (n && n.blocks.length) this.select(n.blocks[0].id, n.blocks[n.blocks.length - 1].id);
  }

  /** Quitte la sélection de blocs ; `caret` : 'start'|'end' pour remettre le curseur. */
  clear(caret) {
    if (!this.active) return;
    const target = caret === 'start' ? this.ids[0] : this.ids[this.ids.length - 1];
    this.ids = [];
    this.anchor = this.head = null;
    this.paint();
    if (caret && this.editor.active) this.editor.focusBlock(target, caret);
  }

  /** Met à jour l'affichage (à rappeler après un nouveau rendu de la carte). */
  paint() {
    const host = this.editor.host;
    this.app.root.classList.toggle('tf-bsel-on', this.active);
    if (!host) return;
    host.querySelectorAll('.tf-card-body > .tf-block').forEach((be) => {
      be.classList.toggle('tf-bsel', this.ids.includes(be.dataset.bid));
    });
  }

  // ------------------------------------------------ souris
  _onPointerDown(e) {
    const ed = this.editor;
    if (!ed.active || e.button !== 0) return;
    const host = ed.host, be = e.target.closest && e.target.closest('.tf-card-body > .tf-block');
    const inHost = be && host.contains(be);
    // la poignée d'un bloc sélectionné déplace tout le groupe (géré par le glisser de blocs)
    if (inHost && e.target.closest('.tf-grip') && this.has(be.dataset.bid)) return;
    if (!inHost) { if (this.active && !e.target.closest('.tf-ui')) this.clear(); return; }
    if (e.shiftKey) {
      e.preventDefault();
      const cur = this.active ? this.anchor : this._caretBlock();
      if (cur) this.select(cur, be.dataset.bid);
      return;
    }
    if (this.active) this.clear();
    if (e.target.closest('.tf-grip,.tf-check,.tf-tog,.tf-mention')) return;
    this._trackDrag(e, be.dataset.bid);
  }

  /** Pendant un glisser commencé dans un bloc : dès qu'on entre dans un autre bloc, on passe en sélection de blocs. */
  _trackDrag(e, startBid) {
    const host = this.editor.host;
    const move = (ev) => {
      if (!this.editor.active || this.editor.host !== host) return end();
      const bid = this._blockAtY(host, ev.clientY);
      if (!bid) return;
      if (!this.active && bid === startBid) return;
      this.app.root.classList.add('tf-bsel-drag');
      window.getSelection().removeAllRanges();
      this.select(startBid, bid);
    };
    const end = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', end);
      window.removeEventListener('pointercancel', end);
      this.app.root.classList.remove('tf-bsel-drag');
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
  }

  _blockAtY(host, y) {
    const list = host.querySelectorAll('.tf-card-body > .tf-block');
    if (!list.length) return null;
    for (const be of list) {
      const r = be.getBoundingClientRect();
      if (y < r.bottom + 1) return be.dataset.bid;
    }
    return list[list.length - 1].dataset.bid;
  }

  _caretBlock() {
    const a = document.activeElement;
    const be = a && a.closest && a.closest('.tf-card-body > .tf-block');
    return be && this.editor.host.contains(be) ? be.dataset.bid : null;
  }

  /** On quitte le texte : l'édition continue, mais le clavier va à la sélection de blocs. */
  _leaveText() {
    const a = document.activeElement;
    if (a && a.isContentEditable) {
      const c = this.editor.blockCtx(a);
      if (c) this.editor.sync(c);
      a.blur();
    }
    this.app.menu.close();
    window.getSelection().removeAllRanges();
    this.app.root.focus({ preventScroll: true });
  }

  // ------------------------------------------------ clavier
  /** Entrée clavier pendant l'édition. @returns {boolean} true si la touche a été traitée. */
  onKey(e) {
    const ed = this.editor, mod = e.ctrlKey || e.metaKey, k = e.key;
    if (!this.active) {
      // Maj+↑/↓ au bord d'un bloc, ou Ctrl+A quand tout le bloc est déjà sélectionné
      const c = ed.blockCtx(e.target);
      if (!c || c.isBody) return false;
      if (e.shiftKey && !mod && (k === 'ArrowUp' || k === 'ArrowDown')) {
        const up = k === 'ArrowUp', n = c.n, nextIdx = c.idx + (up ? -1 : 1);
        if (!ed.atEdge(c.bc, up) || !n.blocks[nextIdx]) return false;
        e.preventDefault();
        this.select(c.b.id, n.blocks[nextIdx].id);
        return true;
      }
      if (mod && k.toLowerCase() === 'a') {
        const s = window.getSelection();
        const all = !c.bc.textContent.length || (s.toString().length >= c.bc.textContent.length);
        if (!all) return false;
        e.preventDefault();
        this.selectAll();
        return true;
      }
      return false;
    }

    e.stopPropagation(); // pas de raccourcis globaux (Suppr = supprimer la carte, Échap = quitter…)
    const n = ed.card;
    if (k === 'Escape') { e.preventDefault(); this.clear('end'); return true; }
    if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); this.remove(); return true; }
    if (mod && k.toLowerCase() === 'a') { e.preventDefault(); this.selectAll(); return true; }
    if (k === 'ArrowUp' || k === 'ArrowDown') {
      e.preventDefault();
      const up = k === 'ArrowUp';
      if (!e.shiftKey) { this.clear(up ? 'start' : 'end'); return true; }
      const i = n.blocks.findIndex((b) => b.id === this.head) + (up ? -1 : 1);
      if (n.blocks[i]) this.select(this.anchor, n.blocks[i].id);
      return true;
    }
    if (k === 'Tab') {
      e.preventDefault();
      let changed = false;
      for (const b of this.blocks()) {
        if (!isList(b.type)) continue;
        b.indent = clamp((b.indent || 0) + (e.shiftKey ? -1 : 1), 0, 4);
        if (!b.indent) delete b.indent;
        changed = true;
      }
      if (changed) ed.refresh();
      return true;
    }
    if (k === 'Enter') { e.preventDefault(); this.clear('end'); return true; }
    // copier / couper / coller : laissés au navigateur (événements copy / cut / paste)
    if (mod && /^[cxv]$/i.test(k)) return true;
    if (mod || k.length !== 1) return true;
    // une lettre tapée remplace les blocs sélectionnés, comme pour du texte
    e.preventDefault();
    if (this.remove(true)) document.execCommand('insertText', false, k);
    return true;
  }

  /**
   * Supprime les blocs sélectionnés et place le curseur à leur place.
   * @param {boolean} [fresh] toujours laisser un bloc texte vide à leur place (pour y saisir ou coller)
   * @returns le bloc où se trouve le curseur
   */
  remove(fresh) {
    const ed = this.editor, n = ed.card;
    if (!n || !this.active) return null;
    const first = n.blocks.findIndex((b) => this.ids.includes(b.id));
    n.blocks = n.blocks.filter((b) => !this.ids.includes(b.id));
    // le curseur va à la fin du bloc précédent, sinon au début du suivant, sinon dans un nouveau bloc vide
    const prev = n.blocks.slice(0, first).reverse().find((b) => !isVoid(b.type));
    const next = n.blocks.slice(first).find((b) => !isVoid(b.type));
    let target = fresh ? null : prev || next, pos = prev ? 'end' : 'start';
    if (!target) { target = nb('text'); n.blocks.splice(first, 0, target); pos = 'start'; }
    this.ids = [];
    this.anchor = this.head = null;
    this.paint();
    ed.refresh();
    ed.focusBlock(target.id, pos);
    this.app.onCardResize(n.id);
    return target;
  }

  _onCopy(e, cut) {
    if (!this.active || !e.clipboardData) return;
    e.preventDefault();
    writeBlocks(e.clipboardData, this.blocks(), (id) => this.app.store.assetUrl(id));
    if (cut) this.remove();
    else this.app.toast(this.ids.length + ' bloc' + (this.ids.length > 1 ? 's' : '') + ' copié' + (this.ids.length > 1 ? 's' : ''));
  }
}
