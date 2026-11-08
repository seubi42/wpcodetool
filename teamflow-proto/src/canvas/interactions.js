/** Interactions souris / touchpad sur le canevas : déplacer, sélectionner, relier, redimensionner, zoomer. */
import { newCard, newEdge } from '../core/model.js';
import { anchor, side, bezier, intersects, containsCenter, containsRect, wheelZoomFactor } from '../core/geometry.js';
import { startBlockDrag } from '../blocks/drag.js';
import { readImage } from '../ui/images.js';
import { nb } from '../core/model.js';

const SPECIALS = '.tf-grip,.tf-check,.tf-tog,.tf-mention,[data-card-act]';
const DBL_DELAY = 450;

export class Interactions {
  /** @param {import('../app.js').App} app */
  constructor(app) {
    this.app = app;
    this.lastDown = null;
    const vp = app.viewport.el;
    vp.addEventListener('pointerdown', (e) => this.onDown(e));
    vp.addEventListener('wheel', (e) => this.onWheel(e), { passive: false });
    vp.addEventListener('dblclick', (e) => this.onDblClick(e));
    vp.addEventListener('click', (e) => this.onClick(e));
    vp.addEventListener('dragover', (e) => {
      if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) e.preventDefault();
    });
    vp.addEventListener('drop', (e) => this.onDrop(e));
    window.addEventListener('pointermove', (e) => { if (app.drag) app.drag.move(e); });
    const end = (e) => { if (app.drag) { const d = app.drag; app.drag = null; d.up(e); } };
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
  }

  /** Clic sur un élément actif d'une carte (poignée, case, lien, outils). */
  handleSpecial(e, sp, scope) {
    const app = this.app;
    const cardEl = sp.closest('.tf-card');
    const cardId = cardEl && cardEl.dataset.id;
    if (sp.classList.contains('tf-mention')) {
      if (app.focus.isOpen) app.focus.close();
      else if (app.editor.active) app.editor.stop();
      app.revealCard(sp.dataset.card);
      return;
    }
    if (sp.dataset.cardAct) {
      const act = sp.dataset.cardAct;
      if (act === 'title') return app.editor.showTitle();
      if (act === 'focus') return app.focus.open(cardId);
      if (act === 'collapse') return app.toggleCollapse(cardId);
      return;
    }
    if (sp.classList.contains('tf-grip')) {
      const be = sp.closest('.tf-block');
      return startBlockDrag(app, e, { cardId, bid: be.dataset.bid, grip: sp, scope });
    }
    app.toggleBlockFlag(sp);
  }

  onDown(e) {
    const app = this.app, t = e.target;
    app.menu.close();
    const special = e.button === 0 && !app.spaceDown ? t.closest(SPECIALS) : null;
    if (app.editor.active) {
      const host = app.editor.host, inside = host && host.contains(t);
      if (inside && !special && !t.closest('.tf-handle,.tf-resize,.tf-open')) return; // laisse l'édition
      const keepEditing = inside && special && !special.classList.contains('tf-mention') && special.dataset.cardAct !== 'collapse';
      if (!keepEditing) app.editor.stop();
    }
    if (special && special.isConnected) {
      e.preventDefault();
      return this.handleSpecial(e, special, app.viewport.el);
    }
    const editable = t.closest('[contenteditable="true"],[contenteditable="plaintext-only"]');
    if (document.activeElement && document.activeElement.isContentEditable && !editable) document.activeElement.blur();
    if (!editable) app.root.focus({ preventScroll: true });

    if (e.button === 1 || (e.button === 0 && app.spaceDown)) { e.preventDefault(); return this.startPan(e); }
    if (e.button !== 0 || editable) return;
    if (t.closest('.tf-open')) return;
    const h = t.closest('.tf-handle');
    if (h) { e.preventDefault(); return this.startConnect(e, h.closest('[data-id]').dataset.id, h.dataset.side); }
    const rs = t.closest('.tf-resize');
    if (rs) { e.preventDefault(); return this.startResize(e, rs.closest('[data-id]').dataset.id); }
    const ne = t.closest('.tf-card, .tf-section-title');
    if (ne) {
      e.preventDefault();
      const id = ne.closest('[data-id]').dataset.id;
      // Double-clic détecté ici : le premier clic remet la carte au premier plan
      // (déplacement dans le DOM), ce qui empêche le navigateur d'émettre « dblclick ».
      const now = Date.now(), lc = this.lastDown;
      this.lastDown = { t: now, x: e.clientX, y: e.clientY, id };
      if (lc && lc.id === id && now - lc.t < DBL_DELAY && Math.hypot(e.clientX - lc.x, e.clientY - lc.y) < 8 && !e.shiftKey) {
        this.lastDown = null;
        if (ne.classList.contains('tf-card')) return app.editor.start(id, { evt: e });
        return app.editSectionTitle(id);
      }
      return this.startMove(e, id);
    }
    const ee = t.closest('.tf-edge');
    if (ee) { e.preventDefault(); app.selectEdge(ee.dataset.id); return; }
    // fond
    e.preventDefault();
    if (app.mode === 'mouse' && !e.shiftKey) { app.clearSelection(); this.startPan(e); }
    else this.startMarquee(e);
  }

  startPan(e) {
    const app = this.app, v = app.viewport.view;
    const sx = e.clientX, sy = e.clientY, x0 = v.x, y0 = v.y;
    app.root.classList.add('tf-panning');
    app.drag = {
      move(ev) { v.x = x0 + ev.clientX - sx; v.y = y0 + ev.clientY - sy; app.viewport.apply(); },
      up() { app.root.classList.remove('tf-panning'); }
    };
  }

  startMarquee(e) {
    const app = this.app, vr = app.viewport.rect;
    const p0 = app.viewport.toWorld(e.clientX, e.clientY), sx = e.clientX, sy = e.clientY;
    const base = e.shiftKey ? new Set(app.selection.ids) : new Set();
    if (!e.shiftKey) { app.selection.clear(); app.render(); }
    const m = app.marquee;
    app.drag = {
      move(ev) {
        m.style.cssText = `display:block;left:${Math.min(sx, ev.clientX) - vr.left}px;top:${Math.min(sy, ev.clientY) - vr.top}px;` +
          `width:${Math.abs(ev.clientX - sx)}px;height:${Math.abs(ev.clientY - sy)}px`;
        const p1 = app.viewport.toWorld(ev.clientX, ev.clientY);
        const r = { x: Math.min(p0.x, p1.x), y: Math.min(p0.y, p1.y), w: Math.abs(p1.x - p0.x), h: Math.abs(p1.y - p0.y) };
        const ids = new Set(base);
        for (const n of app.board.nodes) {
          const nr = app.nodes.rect(n);
          if (n.type === 'section' ? containsRect(r, nr) : intersects(nr, r)) ids.add(n.id);
        }
        app.selection.set(ids);
        for (const [id, el] of app.nodes.els) el.classList.toggle('tf-selected', ids.has(id));
      },
      up() { m.style.display = 'none'; app.render(); }
    };
  }

  startMove(e, id) {
    const app = this.app, n = app.node(id);
    if (!n) return;
    if (e.shiftKey) { app.selection.toggle(id); app.render(); return; }
    if (!app.selection.has(id)) app.selection.set([id]);
    app.selection.edge = null;
    if (n.type === 'card') app.bringToFront(n);
    app.render();

    // éléments déplacés : la sélection + le contenu des sections sélectionnées
    const moving = new Set(app.selection.ids);
    for (const s of app.board.nodes) {
      if (s.type !== 'section' || !app.selection.has(s.id)) continue;
      const sr = app.nodes.rect(s);
      for (const o of app.board.nodes) if (o.id !== s.id && containsCenter(sr, app.nodes.rect(o))) moving.add(o.id);
    }
    const starts = [];
    for (const mid of moving) { const m = app.node(mid); if (m) starts.push({ n: m, x: m.x, y: m.y }); }
    const sx = e.clientX, sy = e.clientY, z = app.viewport.view.z;
    let moved = false;
    app.root.classList.add('tf-moving');
    app.drag = {
      move(ev) {
        if (!moved && Math.hypot(ev.clientX - sx, ev.clientY - sy) < 3) return;
        moved = true;
        const dx = (ev.clientX - sx) / z, dy = (ev.clientY - sy) / z;
        for (const s of starts) {
          s.n.x = Math.round(s.x + dx);
          s.n.y = Math.round(s.y + dy);
          app.nodes.setGeometry(s.n);
        }
        app.edges.updateFor(moving);
        app.minimap.schedule();
        app.toolbars.position();
      },
      up() {
        app.root.classList.remove('tf-moving');
        if (moved) app.commit();
      }
    };
  }

  startResize(e, id) {
    const app = this.app, n = app.node(id);
    if (!n) return;
    const sx = e.clientX, sy = e.clientY, w0 = n.w, h0 = n.h, z = app.viewport.view.z;
    const ids = new Set([id]);
    app.drag = {
      move(ev) {
        n.w = Math.round(Math.max(n.type === 'section' ? 200 : 160, w0 + (ev.clientX - sx) / z));
        if (n.type === 'section') n.h = Math.round(Math.max(120, h0 + (ev.clientY - sy) / z));
        app.nodes.setGeometry(n);
        app.edges.updateFor(ids);
        app.minimap.schedule();
        app.toolbars.position();
      },
      up() { app.commit(); }
    };
  }

  /** Nœud sous le pointeur (carte ou titre de section), hors `exclude`. */
  nodeAt(cx, cy, exclude) {
    for (const x of document.elementsFromPoint(cx, cy)) {
      const c = x.closest && x.closest('.tf-card, .tf-section-title');
      if (c && this.app.viewport.el.contains(c)) {
        const id = c.closest('[data-id]').dataset.id;
        if (id !== exclude) return id;
      }
    }
    return null;
  }

  startConnect(e, fromId, fromSide) {
    const app = this.app, from = app.node(fromId), path = app.edges.temp;
    if (!from) return;
    let target = null;
    app.root.classList.add('tf-connecting');
    const setTarget = (id) => {
      if (target === id) return;
      if (target && app.nodes.get(target)) app.nodes.get(target).classList.remove('tf-drop-target');
      target = id;
      if (target && app.nodes.get(target)) app.nodes.get(target).classList.add('tf-drop-target');
    };
    const draw = (ev) => {
      const p = app.viewport.toWorld(ev.clientX, ev.clientY);
      setTarget(this.nodeAt(ev.clientX, ev.clientY, fromId));
      const ra = app.nodes.rect(from);
      let A = anchor(ra, fromSide), B;
      if (target) {
        const rb = app.nodes.rect(app.node(target));
        A = anchor(ra, side(ra, rb.x + rb.w / 2, rb.y + rb.h / 2));
        B = anchor(rb, side(rb, ra.x + ra.w / 2, ra.y + ra.h / 2));
      } else {
        const dx = p.x - A[0], dy = p.y - A[1];
        B = Math.abs(dx) > Math.abs(dy) ? [p.x, p.y, dx > 0 ? -1 : 1, 0] : [p.x, p.y, 0, dy > 0 ? -1 : 1];
      }
      path.setAttribute('d', bezier(A, B).d);
      path.style.display = 'block';
    };
    draw(e);
    app.drag = {
      move: draw,
      up: (ev) => {
        path.style.display = 'none';
        app.root.classList.remove('tf-connecting');
        const to = target;
        setTarget(null);
        const b = app.board;
        if (to) {
          if (!b.edges.some((x) => x.from === fromId && x.to === to)) { b.edges.push(newEdge(fromId, to)); app.render(); app.commit(); }
          return;
        }
        const p = app.viewport.toWorld(ev.clientX, ev.clientY), ra = app.nodes.rect(from);
        if (Math.hypot(p.x - (ra.x + ra.w / 2), p.y - (ra.y + ra.h / 2)) < Math.max(ra.w, ra.h) / 2 + 20) return;
        // relâché dans le vide : nouvelle carte reliée
        const w = 280, dx = p.x - (ra.x + ra.w / 2), dy = p.y - (ra.y + ra.h / 2);
        let x = p.x - w / 2, y = p.y - 30;
        if (Math.abs(dx) > Math.abs(dy)) x = dx > 0 ? p.x : p.x - w; else y = dy > 0 ? p.y : p.y - 80;
        const n = newCard(x, y, { w, color: from.type === 'card' ? from.color : 'default' });
        b.nodes.push(n);
        b.edges.push(newEdge(fromId, n.id));
        app.selection.set([n.id]);
        app.render();
        app.editor.start(n.id);
      }
    };
  }

  onWheel(e) {
    e.preventDefault();
    const app = this.app;
    app.menu.close();
    const r = app.viewport.rect, sx = e.clientX - r.left, sy = e.clientY - r.top;
    const unit = e.deltaMode === 1 ? 33 : e.deltaMode === 2 ? 400 : 1;
    const dy = e.deltaY * unit, dx = e.deltaX * unit;
    if (e.ctrlKey || e.metaKey) app.viewport.zoomAt(sx, sy, wheelZoomFactor(dy)); // pincement touchpad ou Ctrl+molette
    else if (app.mode === 'touchpad') app.viewport.panBy(-dx, -dy);
    else if (e.shiftKey) app.viewport.panBy(-(dy || dx), 0);
    else app.viewport.zoomAt(sx, sy, wheelZoomFactor(dy));
  }

  onDblClick(e) {
    const app = this.app, t = e.target;
    if (t.closest('.tf-open,.tf-handle,.tf-resize,' + SPECIALS)) return;
    const card = t.closest('.tf-card');
    if (card) {
      if (app.editor.cardId !== card.dataset.id) app.editor.start(card.dataset.id, { evt: e });
      return;
    }
    if (t.closest('.tf-section-title')) return; // géré au pointerdown
    const ed = t.closest('.tf-edge');
    if (ed) return app.editEdgeLabel(ed.dataset.id);
    const p = app.viewport.toWorld(e.clientX, e.clientY);
    app.addCard(p.x - 140, p.y - 24, null, true);
  }

  onClick(e) {
    const o = e.target.closest('.tf-open');
    if (!o) return;
    const n = this.app.node(o.closest('[data-id]').dataset.id);
    if (n && n.childBoardId) this.app.openBoard(n.childBoardId);
  }

  onDrop(e) {
    const app = this.app, files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) return;
    e.preventDefault();
    const cardEl = e.target.closest && e.target.closest('.tf-card');
    const card = cardEl && app.node(cardEl.dataset.id);
    const p = app.viewport.toWorld(e.clientX, e.clientY);
    let i = 0;
    for (const f of Array.from(files)) {
      if (!/^image\//.test(f.type)) continue;
      const off = 30 * i++;
      readImage(f).then((src) => {
        if (card && app.node(card.id)) {
          const last = card.blocks[card.blocks.length - 1];
          app.addImageBlock(card.id, last ? last.id : null, src, false);
        } else {
          const asset = app.store.addAsset(src);
          app.addCard(p.x - 160 + off, p.y - 40 + off, { w: 320, blocks: [nb('image', '', { asset })] }, false);
        }
      });
    }
  }
}
