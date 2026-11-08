/** Vue d'ensemble cliquable (en bas à droite). */
import { COLORS } from '../core/constants.js';

export class Minimap {
  constructor(app, wrap) {
    this.app = app;
    this.wrap = wrap;
    this.canvas = document.createElement('canvas');
    wrap.appendChild(this.canvas);
    this._raf = null;
    this._map = null;
    this.canvas.addEventListener('pointerdown', (e) => this._onDown(e));
  }

  get visible() { return !this.wrap.hidden; }

  setVisible(v) {
    this.wrap.hidden = !v;
    if (v) this.schedule();
  }

  schedule() {
    if (!this.visible || this._raf) return;
    this._raf = requestAnimationFrame(() => { this._raf = null; this.draw(); });
  }

  draw() {
    const app = this.app, cv = this.canvas;
    const W = this.wrap.clientWidth, H = this.wrap.clientHeight;
    if (!W || !H) return;
    const dpr = window.devicePixelRatio || 1;
    if (cv.width !== W * dpr || cv.height !== H * dpr) { cv.width = W * dpr; cv.height = H * dpr; }
    const ctx = cv.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);

    const vw = app.viewport.visibleWorld();
    const b = app.bounds() || vw;
    const x1 = Math.min(b.x, vw.x), y1 = Math.min(b.y, vw.y);
    const x2 = Math.max(b.x + b.w, vw.x + vw.w), y2 = Math.max(b.y + b.h, vw.y + vw.h);
    const pad = 8;
    const s = Math.min((W - pad * 2) / (x2 - x1), (H - pad * 2) / (y2 - y1));
    const ox = pad + ((W - pad * 2) - (x2 - x1) * s) / 2 - x1 * s;
    const oy = pad + ((H - pad * 2) - (y2 - y1) * s) / 2 - y1 * s;
    this._map = { s, ox, oy };

    const css = getComputedStyle(app.root);
    const neutral = css.getPropertyValue('--tf-mm-card').trim() || '#5d616b';
    const accent = css.getPropertyValue('--tf-accent').trim() || '#5b8cff';
    for (const n of app.board.nodes) {
      const r = app.nodes.rect(n), c = COLORS[n.color];
      const x = ox + r.x * s, y = oy + r.y * s, w = Math.max(2, r.w * s), h = Math.max(2, r.h * s);
      if (n.type === 'section') {
        ctx.globalAlpha = 0.14; ctx.fillStyle = c || neutral; ctx.fillRect(x, y, w, h);
        ctx.globalAlpha = 0.8; ctx.strokeStyle = c || neutral; ctx.lineWidth = 1; ctx.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
      } else {
        ctx.globalAlpha = 0.95; ctx.fillStyle = c || neutral;
        roundRect(ctx, x, y, w, h, Math.min(2, w / 4)); ctx.fill();
      }
    }
    ctx.globalAlpha = 1;
    ctx.strokeStyle = accent; ctx.lineWidth = 1.5;
    ctx.fillStyle = 'rgba(120,150,255,0.10)';
    const rx = ox + vw.x * s, ry = oy + vw.y * s;
    ctx.fillRect(rx, ry, vw.w * s, vw.h * s);
    ctx.strokeRect(rx, ry, vw.w * s, vw.h * s);
  }

  _onDown(e) {
    e.preventDefault();
    const m = this._map;
    if (!m) return;
    const rect = this.canvas.getBoundingClientRect();
    const go = (ev) => this.app.viewport.centerOn((ev.clientX - rect.left - m.ox) / m.s, (ev.clientY - rect.top - m.oy) / m.s);
    go(e);
    this.app.drag = { move: go, up() {} };
  }
}

function roundRect(c, x, y, w, h, r) {
  c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
  c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
}
