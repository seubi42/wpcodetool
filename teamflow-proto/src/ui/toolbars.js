/**
 * Barres flottantes : barre de sélection (couleurs, actions) et barre de mise en forme.
 * Elles se placent au-dessus de l'élément actif, en restant entièrement visibles.
 */
import { el } from './dom.js';
import { I, DIR_ICONS } from './icons.js';
import { COLORS, COLOR_LABELS } from '../core/constants.js';
import { esc, clamp } from '../core/util.js';

const TOP_MIN = 60; // sous la barre du haut

export class Toolbars {
  constructor(app) {
    this.app = app;
    this.sel = el('div', 'tf-ui tf-floatbar tf-selbar');
    this.rt = el('div', 'tf-ui tf-floatbar tf-rtbar');
    const rtb = (cmd, inner, title) => `<button class="tf-btn" data-rt="${cmd}" title="${esc(title)}">${inner}</button>`;
    this.rt.innerHTML =
      `<button class="tf-btn" data-rt="insert" title="Insérer un bloc (ou tape « / »)">${I.plus}<span>Bloc</span></button>` +
      '<i class="tf-sep"></i>' +
      rtb('bold', '<b>B</b>', 'Gras (Ctrl+B)') + rtb('italic', '<i>I</i>', 'Italique (Ctrl+I)') + rtb('strike', '<s>S</s>', 'Barré') +
      rtb('icode', I.code, 'Code dans le texte') + rtb('link', I.link, 'Lien web') + rtb('mention', '<b>@</b>', 'Lien vers une carte (ou tape « @ »)') +
      '<i class="tf-sep"></i>' +
      rtb('image', I.image, 'Image') +
      '<i class="tf-sep"></i>' +
      '<button class="tf-btn tf-done" data-rt="done">Terminer</button>';
    app.root.appendChild(this.sel);
    app.root.appendChild(this.rt);
    this.rt.addEventListener('mousedown', (e) => {
      const b = e.target.closest('[data-rt]');
      if (!b) return;
      e.preventDefault(); // garde le curseur dans la carte
      app.editor.command(b.dataset.rt);
    });
    this.sel.addEventListener('click', (e) => {
      const sw = e.target.closest('[data-color]');
      if (sw) return app.setColor(sw.dataset.color);
      const b = e.target.closest('[data-sel]');
      if (b) app.selectionAction(b.dataset.sel);
    });
  }

  buttonRect(cmd) {
    const b = this.rt.querySelector(`[data-rt="${cmd}"]`);
    return b ? b.getBoundingClientRect() : null;
  }

  render() {
    const app = this.app, editing = app.editor.active;
    this.rt.classList.toggle('tf-show', editing);
    if (editing) { this.sel.classList.remove('tf-show'); this.position(); return; }
    const swatches = (cur) => Object.keys(COLORS).map((c) =>
      `<button class="tf-swatch${c === cur ? ' tf-on' : ''}" data-color="${c}" title="${COLOR_LABELS[c]}" style="--c:${COLORS[c] || 'var(--tf-neutral)'}"></button>`).join('');
    const btn = (act, icon, label, title, extra = '') =>
      `<button class="tf-btn${extra}" data-sel="${act}" title="${esc(title)}">${icon}${label ? `<span>${esc(label)}</span>` : ''}</button>`;
    let html = '';
    const ed = app.selection.edge && app.edge(app.selection.edge);
    if (ed) {
      const dir = ed.dir || 'end', style = ed.style || 'solid';
      html = swatches(ed.color) + '<i class="tf-sep"></i>' +
        btn('label', I.label, 'Libellé', 'Libellé de la flèche') + '<i class="tf-sep"></i>' +
        [['start', 'Flèche vers le départ'], ['none', 'Sans flèche'], ['end', 'Flèche vers l\'arrivée'], ['both', 'Flèche dans les deux sens']]
          .map(([d, t]) => btn('dir-' + d, DIR_ICONS[d], '', t, dir === d ? ' tf-toggle tf-on' : ' tf-toggle')).join('') +
        '<i class="tf-sep"></i>' +
        btn('style-solid', I.solid, '', 'Trait plein', style === 'solid' ? ' tf-toggle tf-on' : ' tf-toggle') +
        btn('style-dashed', I.dashed, '', 'Trait pointillé', style === 'dashed' ? ' tf-toggle tf-on' : ' tf-toggle') +
        '<i class="tf-sep"></i>' +
        btn('swap', I.swap, '', 'Inverser le départ et l\'arrivée') +
        btn('delete', I.trash, '', 'Supprimer (Suppr)', ' tf-danger');
    } else if (app.selection.size) {
      const nodes = app.selection.nodes();
      if (nodes.length) {
        const cur = nodes.every((n) => n.color === nodes[0].color) ? nodes[0].color : null;
        if (nodes.length > 1) html += `<span class="tf-selcount">${nodes.length} éléments</span><i class="tf-sep"></i>`;
        html += swatches(cur) + '<i class="tf-sep"></i>';
        if (nodes.length === 1 && nodes[0].type === 'card') {
          const n = nodes[0];
          html += n.childBoardId
            ? btn('open', I.open, 'Ouvrir', 'Ouvrir le sous-whiteboard')
            : btn('to-board', I.board, 'En whiteboard', 'Transformer cette carte en sous-whiteboard');
          html += btn('focus', I.expand, '', 'Plein écran');
        }
        html += btn('duplicate', I.copy, '', 'Dupliquer (Ctrl+D)') + btn('delete', I.trash, '', 'Supprimer (Suppr)', ' tf-danger');
      }
    }
    this.sel.innerHTML = html;
    this.sel.classList.toggle('tf-show', !!html);
    this.position();
  }

  /** Replace les barres visibles au-dessus de leur cible. */
  position() {
    const app = this.app;
    if (this.rt.classList.contains('tf-show')) {
      const host = app.editor.host;
      this._place(this.rt, app.focus.isOpen ? null : host && host.getBoundingClientRect());
    }
    if (this.sel.classList.contains('tf-show')) this._place(this.sel, app.selectionScreenRect());
  }

  _place(bar, rect) {
    const rr = this.app.root.getBoundingClientRect();
    const bw = bar.offsetWidth, bh = bar.offsetHeight, m = 10;
    let x, y;
    if (!rect) { x = (rr.width - bw) / 2; y = TOP_MIN; }
    else {
      x = rect.left + rect.width / 2 - bw / 2 - rr.left;
      y = rect.top - rr.top - bh - 14;
      if (y < TOP_MIN) {
        const below = rect.bottom - rr.top + 14;
        y = below + bh < rr.height - 70 ? below : TOP_MIN;
      }
    }
    bar.style.left = clamp(x, m, Math.max(m, rr.width - bw - m)) + 'px';
    bar.style.top = Math.max(TOP_MIN, y) + 'px';
  }
}
