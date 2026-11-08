/** Gestion du curseur dans un élément éditable (positions en nombre de caractères). */

export function caretOffset(el) {
  const s = window.getSelection();
  if (!s.rangeCount) return 0;
  const r = s.getRangeAt(0);
  if (!el.contains(r.startContainer)) return 0;
  const pre = document.createRange();
  pre.selectNodeContents(el);
  pre.setEnd(r.startContainer, r.startOffset);
  return pre.toString().length;
}

export function rangeAt(el, pos) {
  const r = document.createRange();
  const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  let t, rem = pos;
  while ((t = w.nextNode())) {
    if (rem <= t.length) { r.setStart(t, rem); r.collapse(true); return r; }
    rem -= t.length;
  }
  r.selectNodeContents(el);
  r.collapse(false);
  return r;
}

/** Place le curseur : 'start' | 'end' | position. */
export function setCaret(el, pos) {
  el.focus({ preventScroll: true });
  let r;
  if (pos === 'start' || pos === 0) { r = document.createRange(); r.setStart(el, 0); r.collapse(true); }
  else if (pos === 'end' || pos == null) { r = document.createRange(); r.selectNodeContents(el); r.collapse(false); }
  else r = rangeAt(el, pos);
  const s = window.getSelection();
  s.removeAllRanges();
  s.addRange(r);
}

export function deleteTextRange(el, a, b) {
  const r = rangeAt(el, a), r2 = rangeAt(el, b);
  r.setEnd(r2.startContainer, r2.startOffset);
  r.deleteContents();
  const s = window.getSelection();
  s.removeAllRanges();
  r.collapse(true);
  s.addRange(r);
}

/** Coupe le contenu après le curseur et le renvoie en HTML. */
export function extractTail(el) {
  const r = window.getSelection().getRangeAt(0);
  r.deleteContents();
  const tail = document.createRange();
  tail.setStart(r.startContainer, r.startOffset);
  tail.setEnd(el, el.childNodes.length);
  const d = document.createElement('div');
  d.appendChild(tail.extractContents());
  return d.innerHTML;
}

export function caretRect() {
  const s = window.getSelection();
  if (!s.rangeCount) return null;
  const r = s.getRangeAt(0).cloneRange();
  const rects = r.getClientRects();
  if (rects.length) return rects[0];
  const span = document.createElement('span');
  span.textContent = '​';
  r.insertNode(span);
  const rr = span.getBoundingClientRect();
  const parent = span.parentNode;
  span.remove();
  if (parent) parent.normalize();
  return rr;
}

/** Le curseur est-il sur la première (top=true) ou la dernière ligne de l'élément ? */
export function onEdgeLine(el, top) {
  const c = caretRect();
  if (!c || !c.height) return true;
  const r = el.getBoundingClientRect();
  return top ? (c.top - r.top) < c.height * 0.8 : (r.bottom - c.bottom) < c.height * 0.8;
}

/** Range à partir d'un point écran (Chrome/Edge/Safari et Firefox). */
export function rangeFromPoint(x, y) {
  if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
  if (document.caretPositionFromPoint) {
    const cp = document.caretPositionFromPoint(x, y);
    if (!cp) return null;
    const r = document.createRange();
    r.setStart(cp.offsetNode, cp.offset);
    r.collapse(true);
    return r;
  }
  return null;
}
