/**
 * Calque des flèches (SVG). Chaque flèche garde ses éléments :
 * pendant un déplacement, seules les flèches touchant les nœuds déplacés sont recalculées.
 */
import { COLORS, EDGE_DEFAULT_COLOR } from '../core/constants.js';
import { edgePath } from '../core/geometry.js';
import { el, svgEl } from '../ui/dom.js';
import { esc } from '../core/util.js';

export class EdgeLayer {
  constructor(app, svg, idPrefix) {
    this.app = app;
    this.svg = svg;
    this.prefix = idPrefix;
    this.items = new Map();
    const defs = svgEl('defs');
    const marker = (key, fillAttrs) => {
      const m = svgEl('marker', {
        id: this.markerId(key), viewBox: '0 0 10 10', refX: '8.5', refY: '5',
        markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse', markerUnits: 'strokeWidth'
      });
      m.appendChild(svgEl('path', Object.assign({ d: 'M1,1 L9,5 L1,9 Z' }, fillAttrs)));
      defs.appendChild(m);
    };
    for (const c of Object.keys(COLORS)) marker(c, { fill: COLORS[c] || EDGE_DEFAULT_COLOR });
    marker('sel', { class: 'tf-sel-fill' });
    svg.appendChild(defs);
    this.g = svgEl('g');
    svg.appendChild(this.g);
    this.temp = svgEl('path', { class: 'tf-temp-edge', 'marker-end': `url(#${this.markerId('sel')})` });
    svg.appendChild(this.temp);
  }

  markerId(key) { return this.prefix + '-arrow-' + key; }

  clear() {
    this.g.innerHTML = '';
    this.items.clear();
  }

  /** Aligne toutes les flèches sur le whiteboard courant. */
  sync() {
    const seen = new Set();
    for (const ed of this.app.board.edges) {
      seen.add(ed.id);
      let it = this.items.get(ed.id);
      if (!it) { it = this._create(ed); this.items.set(ed.id, it); }
      this._style(it, ed);
      this._geometry(it, ed);
    }
    for (const [id, it] of this.items) if (!seen.has(id)) { it.g.remove(); this.items.delete(id); }
  }

  /** Recalcule seulement les flèches reliées à ces nœuds. */
  updateFor(nodeIds) {
    for (const ed of this.app.board.edges) {
      if (!nodeIds.has(ed.from) && !nodeIds.has(ed.to)) continue;
      const it = this.items.get(ed.id);
      if (it) this._geometry(it, ed);
    }
  }

  _create(ed) {
    const g = svgEl('g', { class: 'tf-edge', 'data-id': ed.id });
    const hit = svgEl('path', { class: 'tf-edge-hit' });
    const line = svgEl('path', { class: 'tf-edge-line' });
    g.appendChild(hit);
    g.appendChild(line);
    this.g.appendChild(g);
    return { g, hit, line, fo: null, sig: '' };
  }

  _style(it, ed) {
    const selected = this.app.selection.edge === ed.id;
    const sig = [ed.color, ed.dir, ed.style, ed.label, selected].join('|');
    if (it.sig === sig) return;
    it.sig = sig;
    const col = COLORS[ed.color] || EDGE_DEFAULT_COLOR;
    const mk = `url(#${this.markerId(selected ? 'sel' : (COLORS[ed.color] ? ed.color : 'default'))})`;
    const dir = ed.dir || 'end';
    it.g.classList.toggle('tf-selected', selected);
    it.line.setAttribute('stroke', selected ? '' : col);
    it.line.classList.toggle('tf-dashed', ed.style === 'dashed');
    if (dir === 'end' || dir === 'both') it.line.setAttribute('marker-end', mk); else it.line.removeAttribute('marker-end');
    if (dir === 'start' || dir === 'both') it.line.setAttribute('marker-start', mk); else it.line.removeAttribute('marker-start');
    if (it.fo) { it.fo.remove(); it.fo = null; }
    if (ed.label) {
      it.fo = svgEl('foreignObject', { width: 200, height: 28, class: 'tf-edge-label-fo' });
      const lab = el('div', 'tf-edge-label', `<span>${esc(ed.label)}</span>`);
      lab.style.setProperty('--c', col);
      it.fo.appendChild(lab);
      it.g.appendChild(it.fo);
    }
  }

  _geometry(it, ed) {
    const a = this.app.node(ed.from), b = this.app.node(ed.to);
    if (!a || !b) { it.g.style.display = 'none'; return; }
    it.g.style.display = '';
    const p = edgePath(this.app.nodes.rect(a), this.app.nodes.rect(b));
    it.hit.setAttribute('d', p.d);
    it.line.setAttribute('d', p.d);
    if (it.fo) { it.fo.setAttribute('x', p.mid[0] - 100); it.fo.setAttribute('y', p.mid[1] - 14); }
  }
}
