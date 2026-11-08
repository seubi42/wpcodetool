/**
 * Glisser un bloc par sa poignée : réordonner, changer de carte, ou créer une carte sur le canevas.
 * Si le bloc fait partie d'une sélection de blocs, tout le groupe est déplacé.
 */
import { el } from '../ui/dom.js';
import { newCard } from '../core/model.js';
import { BLOCK_TYPES } from './types.js';
import { blockText } from './model.js';

/**
 * @param {import('../app.js').App} app
 * @param {PointerEvent} e
 * @param {{cardId:string, bid:string, grip:HTMLElement, scope:HTMLElement}} o
 *   scope : conteneur où chercher les cartes cibles (canevas ou vue plein écran)
 */
export function startBlockDrag(app, e, { cardId, bid, grip, scope }) {
  const src = app.node(cardId);
  if (!src) return;
  const bsel = app.editor.bsel;
  const group = app.editor.cardId === cardId && bsel.has(bid) ? bsel.ids.slice() : [bid];
  const blockEls = group.map((id) => grip.closest('.tf-card').querySelector(`.tf-block[data-bid="${id}"]`)).filter(Boolean);
  const sx = e.clientX, sy = e.clientY, rr = app.root.getBoundingClientRect();
  const onCanvas = scope === app.viewport.el;
  let moved = false, ghost = null, line = null, drop = null;

  const findDrop = (ev) => {
    let card = null;
    for (const x of document.elementsFromPoint(ev.clientX, ev.clientY)) {
      if (x.closest('.tf-ui')) continue;
      const c = x.closest('.tf-card');
      if (c && scope.contains(c)) { card = c; break; }
    }
    if (!card) return onCanvas ? { canvas: true } : null;
    const blocks = Array.from(card.querySelectorAll('.tf-card-body > .tf-block'));
    let idx = blocks.length;
    for (let j = 0; j < blocks.length; j++) {
      const r = blocks[j].getBoundingClientRect();
      if (ev.clientY < r.top + r.height / 2) { idx = j; break; }
    }
    let top;
    if (!blocks.length) { const hd = card.querySelector('.tf-card-head'); top = hd.offsetTop + hd.offsetHeight + 4; }
    else if (idx < blocks.length) top = blocks[idx].offsetTop;
    else top = blocks[blocks.length - 1].offsetTop + blocks[blocks.length - 1].offsetHeight;
    return { card, idx, top };
  };

  app.drag = {
    move(ev) {
      if (!moved) {
        if (Math.hypot(ev.clientX - sx, ev.clientY - sy) < 4) return;
        const b = src.blocks.find((x) => x.id === bid);
        if (!b) return;
        moved = true;
        blockEls.forEach((x) => x.classList.add('tf-dragging'));
        app.root.classList.add('tf-block-dragging');
        ghost = el('div', 'tf-ui tf-block-ghost',
          `<span class="tf-menu-ic">${BLOCK_TYPES[b.type].icon}</span><span class="tf-ghost-t"></span>`);
        ghost.querySelector('.tf-ghost-t').textContent = group.length > 1
          ? group.length + ' blocs'
          : blockText(b).trim().slice(0, 60) || BLOCK_TYPES[b.type].label;
        app.root.appendChild(ghost);
        line = el('div', 'tf-drop-line');
      }
      ghost.style.left = (ev.clientX - rr.left + 14) + 'px';
      ghost.style.top = (ev.clientY - rr.top + 12) + 'px';
      drop = findDrop(ev);
      ghost.classList.toggle('tf-ghost-new', !!(drop && drop.canvas));
      if (drop && drop.card) {
        if (line.parentNode !== drop.card) drop.card.appendChild(line);
        line.style.top = drop.top + 'px';
        line.style.display = 'block';
      } else line.style.display = 'none';
    },
    up(ev) {
      app.root.classList.remove('tf-block-dragging');
      if (!moved) { app.editor.openBlockMenu(cardId, bid, grip.getBoundingClientRect()); return; }
      blockEls.forEach((x) => x.classList.remove('tf-dragging'));
      ghost.remove();
      line.remove();
      const s = app.node(cardId);
      if (!s || !drop) return;
      const idx = group.map((id) => s.blocks.findIndex((x) => x.id === id)).filter((i) => i >= 0).sort((a, b) => a - b);
      if (!idx.length) return;
      const moving = idx.map((i) => s.blocks[i]);
      const takeOut = () => { s.blocks = s.blocks.filter((x) => !moving.includes(x)); };
      let tgt;
      if (drop.card) {
        tgt = app.node(drop.card.dataset.id);
        if (!tgt) return;
        let ti = drop.idx;
        if (tgt === s) {
          if (ti >= idx[0] && ti <= idx[idx.length - 1] + 1) return; // lâché sur place
          ti -= idx.filter((i) => i < ti).length;
          takeOut();
          s.blocks.splice(ti, 0, ...moving);
        } else {
          takeOut();
          tgt.blocks.splice(ti, 0, ...moving);
        }
      } else {
        const p = app.viewport.toWorld(ev.clientX, ev.clientY);
        takeOut();
        const img = moving.length === 1 && moving[0].type === 'image';
        tgt = newCard(p.x - 24, p.y - 20, { blocks: moving, w: img ? 320 : 280 });
        app.board.nodes.push(tgt);
      }
      if (group.length > 1 && tgt !== s && app.editor.cardId === s.id) bsel.clear();
      if (tgt !== s && !s.title && !s.blocks.length && !s.childBoardId && app.editor.cardId !== s.id) app.removeNodes([s.id]);
      app.render();
      for (const n of [s, tgt]) if (app.node(n.id)) app.refreshCard(n.id);
      app.commit();
    }
  };
}
