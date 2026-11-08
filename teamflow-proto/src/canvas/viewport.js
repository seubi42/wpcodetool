/** Vue du canevas : translation + zoom, conversions écran <-> monde. */
import { clamp } from '../core/util.js';
import { ZOOM_MIN, ZOOM_MAX } from '../core/constants.js';

export class Viewport {
  /**
   * @param {import('../app.js').App} app
   * @param {HTMLElement} el   zone visible (reçoit les événements)
   * @param {HTMLElement} world  calque transformé
   */
  constructor(app, el, world) {
    this.app = app;
    this.el = el;
    this.world = world;
    this._animT = null;
  }

  /** Vue du whiteboard courant (mémorisée par whiteboard). */
  get view() {
    const views = this.app.store.views, id = this.app.boardId;
    if (!views[id]) views[id] = { x: 0, y: 0, z: 1, fresh: true };
    return views[id];
  }

  get rect() { return this.el.getBoundingClientRect(); }

  apply() {
    const v = this.view;
    this.world.style.transform = `translate(${v.x}px,${v.y}px) scale(${v.z})`;
    let g = 24 * v.z;
    while (g < 10) g *= 2;
    this.el.style.backgroundSize = `${g}px ${g}px`;
    this.el.style.backgroundPosition = `${v.x}px ${v.y}px`;
    this.app.onViewChange();
  }

  toWorld(cx, cy) {
    const r = this.rect, v = this.view;
    return { x: (cx - r.left - v.x) / v.z, y: (cy - r.top - v.y) / v.z };
  }

  /** Rectangle monde -> rectangle écran (coordonnées client). */
  toScreenRect(wr) {
    const r = this.rect, v = this.view;
    return { left: r.left + v.x + wr.x * v.z, top: r.top + v.y + wr.y * v.z, width: wr.w * v.z, height: wr.h * v.z,
      get right() { return this.left + this.width; }, get bottom() { return this.top + this.height; } };
  }

  /** Rectangle monde actuellement visible. */
  visibleWorld() {
    const r = this.rect, v = this.view;
    return { x: -v.x / v.z, y: -v.y / v.z, w: r.width / v.z, h: r.height / v.z };
  }

  zoomAt(sx, sy, factor, animate) {
    const v = this.view;
    const z1 = clamp(v.z * factor, ZOOM_MIN, ZOOM_MAX);
    const wx = (sx - v.x) / v.z, wy = (sy - v.y) / v.z;
    v.x = sx - wx * z1;
    v.y = sy - wy * z1;
    v.z = z1;
    if (animate) this.animate();
    this.apply();
  }

  zoomCenter(factor) {
    const r = this.rect;
    this.zoomAt(r.width / 2, r.height / 2, factor, true);
  }

  panBy(dx, dy) {
    const v = this.view;
    v.x += dx;
    v.y += dy;
    this.apply();
  }

  /** Cadre le rectangle monde `b` (ou tout le contenu). */
  fit(b, animate, maxZoom = 1) {
    const r = this.rect, v = this.view;
    if (!b) { v.x = r.width / 2 - 140; v.y = r.height / 2 - 60; v.z = 1; }
    else {
      const pad = 90;
      v.z = clamp(Math.min((r.width - pad * 2) / b.w, (r.height - pad * 2 - 40) / b.h), ZOOM_MIN, maxZoom);
      v.x = r.width / 2 - (b.x + b.w / 2) * v.z;
      v.y = r.height / 2 + 20 - (b.y + b.h / 2) * v.z;
    }
    if (animate) this.animate();
    this.apply();
  }

  centerOn(wx, wy, animate) {
    const r = this.rect, v = this.view;
    v.x = r.width / 2 - wx * v.z;
    v.y = r.height / 2 - wy * v.z;
    if (animate) this.animate();
    this.apply();
  }

  animate() {
    this.world.classList.add('tf-anim');
    clearTimeout(this._animT);
    this._animT = setTimeout(() => this.world.classList.remove('tf-anim'), 300);
  }
}
