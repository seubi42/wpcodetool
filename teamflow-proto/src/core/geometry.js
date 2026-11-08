/** Géométrie pure : ancrages et courbes des flèches, tests de rectangles, zoom. */
import { clamp } from './util.js';

/** Côté de `r` le plus proche du point (tx, ty) : 't' | 'r' | 'b' | 'l'. */
export function side(r, tx, ty) {
  const cx = r.x + r.w / 2, cy = r.y + r.h / 2;
  const dx = (tx - cx) / (r.w / 2), dy = (ty - cy) / (r.h / 2);
  if (Math.abs(dx) > Math.abs(dy)) return dx > 0 ? 'r' : 'l';
  return dy > 0 ? 'b' : 't';
}

/** Point d'ancrage au milieu d'un côté, avec la normale sortante : [x, y, nx, ny]. */
export function anchor(r, s) {
  switch (s) {
    case 'r': return [r.x + r.w, r.y + r.h / 2, 1, 0];
    case 'l': return [r.x, r.y + r.h / 2, -1, 0];
    case 't': return [r.x + r.w / 2, r.y, 0, -1];
    default: return [r.x + r.w / 2, r.y + r.h, 0, 1];
  }
}

/** Courbe de Bézier entre deux ancrages ; renvoie le chemin SVG et son milieu. */
export function bezier(A, B) {
  const d = Math.hypot(B[0] - A[0], B[1] - A[1]);
  const k = clamp(d * 0.4, 24, 160);
  const c1 = [A[0] + A[2] * k, A[1] + A[3] * k];
  const c2 = [B[0] + B[2] * k, B[1] + B[3] * k];
  return {
    d: `M${A[0]},${A[1]} C${c1[0]},${c1[1]} ${c2[0]},${c2[1]} ${B[0]},${B[1]}`,
    mid: [
      0.125 * A[0] + 0.375 * c1[0] + 0.375 * c2[0] + 0.125 * B[0],
      0.125 * A[1] + 0.375 * c1[1] + 0.375 * c2[1] + 0.125 * B[1]
    ]
  };
}

/** Courbe reliant deux rectangles par leurs côtés en vis-à-vis. */
export function edgePath(ra, rb) {
  const A = anchor(ra, side(ra, rb.x + rb.w / 2, rb.y + rb.h / 2));
  const B = anchor(rb, side(rb, ra.x + ra.w / 2, ra.y + ra.h / 2));
  return bezier(A, B);
}

export function intersects(a, b) {
  return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

export function containsCenter(outer, r) {
  const cx = r.x + r.w / 2, cy = r.y + r.h / 2;
  return cx > outer.x && cx < outer.x + outer.w && cy > outer.y && cy < outer.y + outer.h;
}

export function containsRect(outer, r) {
  return r.x >= outer.x && r.y >= outer.y && r.x + r.w <= outer.x + outer.w && r.y + r.h <= outer.y + outer.h;
}

/** Facteur de zoom pour un événement molette / pincement : ~10 % par cran, progressif au pincement. */
export function wheelZoomFactor(dy) {
  const step = Math.min(Math.abs(dy) * 0.01, 0.1);
  return Math.exp(dy > 0 ? -step : step);
}
