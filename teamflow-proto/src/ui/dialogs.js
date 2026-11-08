/** Boîtes de dialogue de l'application (remplacent alert / confirm / prompt du navigateur). */
import { el } from './dom.js';
import { esc } from '../core/util.js';

export class Dialogs {
  constructor(root) {
    this.root = root;
    this.open = null;
  }

  get isOpen() { return !!this.open; }

  /** @returns {Promise<boolean>} */
  confirm({ title, message = '', ok = 'Confirmer', cancel = 'Annuler', danger = false }) {
    return this._show({ title, message, ok, cancel, danger }).then((r) => r !== null);
  }

  /** @returns {Promise<string|null>} null si annulé */
  prompt({ title, message = '', value = '', placeholder = '', ok = 'Valider', cancel = 'Annuler' }) {
    return this._show({ title, message, ok, cancel, input: { value, placeholder } });
  }

  _show(o) {
    if (this.open) this.open.finish(null);
    const prevFocus = document.activeElement;
    const back = el('div', 'tf-modal-back');
    back.innerHTML =
      '<div class="tf-modal" role="dialog" aria-modal="true">' +
      `<div class="tf-modal-title">${esc(o.title)}</div>` +
      (o.message ? `<div class="tf-modal-msg">${esc(o.message)}</div>` : '') +
      (o.input ? `<input class="tf-modal-input" type="text" placeholder="${esc(o.input.placeholder)}">` : '') +
      '<div class="tf-modal-actions">' +
      `<button class="tf-btn tf-modal-cancel">${esc(o.cancel)}</button>` +
      `<button class="tf-btn tf-modal-ok${o.danger ? ' tf-danger-solid' : ''}">${esc(o.ok)}</button>` +
      '</div></div>';
    const input = back.querySelector('.tf-modal-input');
    if (input) input.value = o.input.value;
    this.root.appendChild(back);

    return new Promise((resolve) => {
      const finish = (v) => {
        if (!this.open) return;
        this.open = null;
        back.remove();
        if (prevFocus && prevFocus.focus) prevFocus.focus({ preventScroll: true });
        resolve(v);
      };
      this.open = { finish };
      const okValue = () => (input ? input.value : true);
      back.addEventListener('pointerdown', (e) => { e.stopPropagation(); if (e.target === back) finish(null); });
      back.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });
      back.addEventListener('keydown', (e) => {
        e.stopPropagation();
        if (e.key === 'Escape') { e.preventDefault(); finish(null); }
        if (e.key === 'Enter') { e.preventDefault(); finish(okValue()); }
      });
      back.querySelector('.tf-modal-cancel').addEventListener('click', () => finish(null));
      back.querySelector('.tf-modal-ok').addEventListener('click', () => finish(okValue()));
      requestAnimationFrame(() => {
        if (input) { input.focus(); input.select(); }
        else back.querySelector('.tf-modal-ok').focus();
      });
    });
  }
}
