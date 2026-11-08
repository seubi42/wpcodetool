/**
 * Menu flottant filtrable (menu « / », liens « @ », menu de bloc).
 * Une seule instance par application ; ouvrir un menu ferme le précédent.
 */
import { el } from './dom.js';
import { esc, clamp, norm } from '../core/util.js';

export class Menu {
  constructor(root) {
    this.root = root;
    this.current = null;
  }

  get isOpen() { return !!this.current; }

  /**
   * @param {Array<{id?, label, icon?, hint?, keys?, group?, on?, danger?}>} items  (`group` = intitulé de section)
   * @param {DOMRect|null} rect  ancre à l'écran
   * @param {(item, meta) => void} onPick
   * @param {{header?: string, empty?: string, meta?: any, filterable?: boolean}} [opts]
   */
  open(items, rect, onPick, opts = {}) {
    this.close();
    const m = el('div', 'tf-ui tf-menu');
    const rr = this.root.getBoundingClientRect();
    const state = { filter: '', active: 0, list: [] };

    const render = () => {
      const f = norm(state.filter);
      const shown = items.filter((it) => it.group ? !f : (!f || norm(it.label + ' ' + (it.hint || '') + ' ' + (it.keys || '')).includes(f)));
      state.list = shown.filter((it) => !it.group);
      if (state.active >= state.list.length) state.active = 0;
      let html = opts.header ? `<div class="tf-menu-head">${f ? 'Filtre : <b>' + esc(state.filter) + '</b>' : esc(opts.header)}</div>` : '';
      if (!state.list.length) html += `<div class="tf-menu-empty">${esc(opts.empty || 'Aucun résultat')}</div>`;
      let k = 0;
      for (const it of shown) {
        if (it.group) { html += `<div class="tf-menu-group">${esc(it.group)}</div>`; continue; }
        html += `<div class="tf-menu-item${k === state.active ? ' tf-active' : ''}${it.danger ? ' tf-danger' : ''}" data-i="${k}">` +
          `<span class="tf-menu-ic">${it.icon || ''}</span><span class="tf-menu-label">${esc(it.label)}` +
          (it.hint ? `<small>${esc(it.hint)}</small>` : '') + '</span>' +
          (it.on ? '<span class="tf-menu-check">✓</span>' : '') + '</div>';
        k++;
      }
      m.innerHTML = html;
      const a = m.querySelector('.tf-active');
      if (a && a.scrollIntoView) a.scrollIntoView({ block: 'nearest' });
    };

    const pick = (i) => {
      const it = state.list[i];
      if (!it) return;
      const meta = this.current && this.current.meta;
      this.close();
      onPick(it, meta);
    };

    m.addEventListener('mousedown', (e) => e.preventDefault()); // garde le focus dans l'éditeur
    m.addEventListener('pointerdown', (e) => e.stopPropagation());
    m.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });
    m.addEventListener('click', (e) => { const it = e.target.closest('.tf-menu-item'); if (it) pick(+it.dataset.i); });
    m.addEventListener('mousemove', (e) => {
      const it = e.target.closest('.tf-menu-item');
      if (it && +it.dataset.i !== state.active) {
        state.active = +it.dataset.i;
        m.querySelectorAll('.tf-menu-item').forEach((x) => x.classList.toggle('tf-active', +x.dataset.i === state.active));
      }
    });

    this.root.appendChild(m);
    render();
    let x = (rect ? rect.left : rr.left + rr.width / 2 - 130) - rr.left;
    let y = (rect ? rect.bottom + 6 : rr.top + 120) - rr.top;
    const mh = m.offsetHeight, mw = m.offsetWidth;
    if (rect && y + mh > rr.height - 10) y = Math.max(10, rect.top - rr.top - mh - 6);
    m.style.left = clamp(x, 10, rr.width - mw - 10) + 'px';
    m.style.top = y + 'px';

    this.current = {
      el: m,
      meta: opts.meta,
      setFilter: (f) => { state.filter = f; state.active = 0; render(); },
      key: (e) => {
        const len = Math.max(1, state.list.length);
        if (e.key === 'ArrowDown') { e.preventDefault(); state.active = (state.active + 1) % len; render(); return true; }
        if (e.key === 'ArrowUp') { e.preventDefault(); state.active = (state.active - 1 + len) % len; render(); return true; }
        if (e.key === 'Enter' || e.key === 'Tab') {
          if (!state.list.length) { this.close(); return false; }
          e.preventDefault(); pick(state.active); return true;
        }
        if (e.key === 'Escape') { e.preventDefault(); this.close(); return true; }
        return false;
      }
    };
    return this.current;
  }

  /** Transmet une touche au menu ouvert ; renvoie true si elle a été consommée. */
  key(e) { return this.current ? this.current.key(e) : false; }

  setFilter(f) { if (this.current) this.current.setFilter(f); }

  get meta() { return this.current ? this.current.meta : null; }

  close() {
    if (this.current) { this.current.el.remove(); this.current = null; }
  }
}
