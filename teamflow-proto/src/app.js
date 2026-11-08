/**
 * App : assemble les modules et porte les actions de haut niveau
 * (navigation entre whiteboards, sélection, création / suppression, annulation).
 */
import { Store } from './core/store.js';
import { Selection } from './core/selection.js';
import { newBoard, newCard, newSection, nb, isValidDoc } from './core/model.js';
import { uid, clone, prefs } from './core/util.js';
import { el, svgEl } from './ui/dom.js';
import { I } from './ui/icons.js';
import { buildChrome } from './ui/chrome.js';
import { Menu } from './ui/menu.js';
import { Dialogs } from './ui/dialogs.js';
import { Toolbars } from './ui/toolbars.js';
import { bindKeyboard } from './ui/keyboard.js';
import { readImage } from './ui/images.js';
import { Viewport } from './canvas/viewport.js';
import { NodeLayer } from './canvas/nodes.js';
import { EdgeLayer } from './canvas/edges.js';
import { Minimap } from './canvas/minimap.js';
import { Interactions } from './canvas/interactions.js';
import { CardEditor } from './blocks/editor.js';
import { cardSummary } from './blocks/model.js';
import { isVoid } from './blocks/types.js';
import { FocusView } from './features/focus.js';
import { demoData } from './core/demo.js';
import { localStorageAdapter } from './storage/local.js';

export class App {
  /**
   * @param {HTMLElement} host
   * @param {{storage?: {load():Promise<object|null>, save(doc):Promise<void>}}} [opts]
   */
  constructor(host, opts = {}) {
    this.store = new Store(opts.storage || localStorageAdapter('teamflow'));
    this.id = uid('tf');
    this.boardId = null;
    this.mode = prefs.get('mode') || 'mouse';
    this.theme = prefs.get('theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    this.spaceDown = false;
    this.drag = null; // { move(e), up(e) } : opération de glisser en cours

    this._buildDom(host);
    this.selection = new Selection(this);
    this.menu = new Menu(this.root);
    this.dialogs = new Dialogs(this.root);
    this.viewport = new Viewport(this, this.vp, this.world);
    this.nodes = new NodeLayer(this, this.sectionsLayer, this.cardsLayer);
    this.edges = new EdgeLayer(this, this.svg, this.id);
    this.minimap = new Minimap(this, this.chrome.minimapWrap);
    this.editor = new CardEditor(this);
    this.focus = new FocusView(this);
    this.toolbars = new Toolbars(this);
    this.interactions = new Interactions(this);
    this.renderCtx = {
      assetUrl: (id) => this.store.assetUrl(id),
      mentionLabel: (id) => { const hit = this.store.findCard(id); return hit ? cardSummary(hit.node) : null; },
      childCount: (bid) => { const b = this.store.board(bid); return b ? b.nodes.filter((x) => x.type === 'card').length : 0; }
    };
    this._bind();
    bindKeyboard(this);
    this.minimap.setVisible(prefs.get('map') !== '0');
    this._renderModeUI();
    this._renderThemeUI();

    this.store.on((type, payload) => {
      if (type === 'saving') this._status('Modifications…');
      else if (type === 'saved') this._status('Enregistré');
      else if (type === 'save-error') {
        this._status('Erreur d\'enregistrement', true);
        this.toast('Impossible d\'enregistrer : ' + (payload && payload.name === 'QuotaExceededError'
          ? 'stockage du navigateur plein (images trop lourdes ?)' : (payload && payload.message) || payload));
      }
    });
    this._status('Chargement…');
    this.ready = this.store.load().then(() => { this._status(''); this.openBoard(this.store.rootBoardId); return this; });
  }

  // ------------------------------------------------ DOM
  _buildDom(host) {
    const r = this.root = el('div', 'tf-root');
    r.tabIndex = 0;
    r.dataset.mode = this.mode;
    r.dataset.theme = this.theme;
    this.vp = el('div', 'tf-viewport');
    this.world = el('div', 'tf-world');
    this.sectionsLayer = el('div', 'tf-layer tf-sections');
    this.cardsLayer = el('div', 'tf-layer tf-cards');
    this.svg = svgEl('svg', { class: 'tf-edges', width: '1', height: '1' });
    this.world.append(this.sectionsLayer, this.svg, this.cardsLayer);
    this.marquee = el('div', 'tf-marquee');
    this.vp.append(this.world, this.marquee);
    r.appendChild(this.vp);
    this.chrome = buildChrome(r);
    host.innerHTML = '';
    host.appendChild(r);
  }

  _bind() {
    const r = this.root;
    r.addEventListener('click', (e) => {
      const b = e.target.closest('[data-act]');
      if (b) return this.action(b.dataset.act);
      const c = e.target.closest('.tf-crumb');
      if (c) this.openBoard(c.dataset.board);
    });
    // saisie dans la carte éditée (canevas ou plein écran)
    r.addEventListener('keydown', (e) => { if (this.editor.active) this.editor.onKey(e); });
    r.addEventListener('input', (e) => { if (this.editor.active) this.editor.onInput(e); });
    r.addEventListener('paste', (e) => { if (this.editor.active) this.editor.onPaste(e); });
    this.chrome.fileImport.addEventListener('change', () => {
      const f = this.chrome.fileImport.files[0];
      if (f) this.importJSON(f);
    });
    this.chrome.fileImage.addEventListener('change', () => {
      const f = this.chrome.fileImage.files[0], cb = this._imgCb;
      this._imgCb = null;
      if (f && cb) readImage(f).then(cb);
    });
    window.addEventListener('resize', () => { this.minimap.schedule(); this.toolbars.position(); });
  }

  isActive() {
    const a = document.activeElement;
    return a === document.body || a === null || this.root.contains(a);
  }

  // ------------------------------------------------ accès
  get board() { return this.store.board(this.boardId); }
  node(id) { return this.board.nodes.find((n) => n.id === id); }
  edge(id) { return this.board.edges.find((e) => e.id === id); }

  boardLabel(b) {
    if (b.parent) {
      const pb = this.store.board(b.parent.boardId);
      const n = pb && pb.nodes.find((x) => x.id === b.parent.nodeId);
      if (n) return cardSummary(n);
    }
    return b.title;
  }

  /** Rectangle monde englobant tous les nœuds (ou ceux donnés). */
  bounds(nodes = this.board.nodes) {
    if (!nodes.length) return null;
    let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    for (const n of nodes) {
      const r = this.nodes.rect(n), top = n.type === 'section' ? r.y - 44 : r.y;
      x1 = Math.min(x1, r.x); y1 = Math.min(y1, top); x2 = Math.max(x2, r.x + r.w); y2 = Math.max(y2, r.y + r.h);
    }
    return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
  }

  // ------------------------------------------------ rendu
  render() {
    this.nodes.sync();
    this.edges.sync();
    this.toolbars.render();
    this.minimap.schedule();
  }

  /** Re-rend le contenu d'une carte (canevas et plein écran). */
  refreshCard(id) {
    const n = this.node(id);
    if (!n) return;
    this.nodes.refresh(n);
    if (this.focus.isOpenFor(id)) this.focus.render();
    if (this.editor.cardId === id) {
      this.editor.setEditable(this.editor.host, true);
      this.editor.bsel.paint();
    }
    this.onCardResize(id);
  }

  onCardResize(id) {
    if (id) this.edges.updateFor(new Set([id]));
    this.minimap.schedule();
    this.toolbars.position();
  }

  onViewChange() {
    this.chrome.zoomLabel.textContent = Math.round(this.viewport.view.z * 100) + '%';
    this.minimap.schedule();
    this.toolbars.position();
    this.store.saveViews();
  }

  commit() { return this.store.commit(); }

  // ------------------------------------------------ navigation
  openBoard(id) {
    if (!this.store.board(id)) return;
    if (this.focus.isOpen) this.focus.close();
    if (this.editor.active) this.editor.stop();
    this.menu.close();
    this.selection.clear();
    this.boardId = id;
    this.nodes.clear();
    this.edges.clear();
    this.render();
    this._renderCrumbs();
    const v = this.viewport.view;
    if (v.fresh) { delete v.fresh; this.fit(false); } else this.viewport.apply();
  }

  goUp() {
    const b = this.board;
    if (!b.parent || !this.store.board(b.parent.boardId)) return;
    const nodeId = b.parent.nodeId;
    this.openBoard(b.parent.boardId);
    if (this.node(nodeId)) { this.selection.set([nodeId]); this.render(); }
  }

  /** Va à une carte, quel que soit son whiteboard, et la met en évidence. */
  revealCard(cardId) {
    const hit = this.store.findCard(cardId);
    if (!hit) { this.toast('Cette carte n\'existe plus'); return; }
    if (hit.board.id !== this.boardId) this.openBoard(hit.board.id);
    const n = this.node(cardId), r = this.nodes.rect(n);
    if (this.viewport.view.z < 0.6) this.viewport.view.z = 0.8;
    this.viewport.centerOn(r.x + r.w / 2, r.y + r.h / 2, true);
    this.selection.set([cardId]);
    this.render();
    const e = this.nodes.get(cardId);
    if (e) { e.classList.remove('tf-flash'); void e.offsetWidth; e.classList.add('tf-flash'); }
  }

  fit() { this.viewport.fit(this.bounds(), true); }

  _renderCrumbs() {
    const chain = [];
    let b = this.board, guard = 0;
    while (b && guard++ < 50) { chain.unshift(b); b = b.parent ? this.store.board(b.parent.boardId) : null; }
    const span = document.createElement('span');
    this.chrome.crumbs.innerHTML = chain.map((x, i) => {
      span.textContent = this.boardLabel(x);
      return (i ? '<span class="tf-crumb-sep">/</span>' : '') +
        `<button class="tf-crumb${i === chain.length - 1 ? ' tf-current' : ''}" data-board="${x.id}">${span.innerHTML}</button>`;
    }).join('');
  }

  // ------------------------------------------------ sélection
  select(ids) { this.selection.set(ids); this.render(); }
  clearSelection() { if (!this.selection.empty) { this.selection.clear(); this.render(); } }
  selectEdge(id) { this.selection.setEdge(id); this.render(); }

  /** Rectangle écran de la sélection (pour placer la barre d'outils). */
  selectionScreenRect() {
    const ed = this.selection.edge && this.edge(this.selection.edge);
    if (ed) {
      const a = this.node(ed.from), b = this.node(ed.to);
      if (!a || !b) return null;
      const ra = this.nodes.rect(a), rb = this.nodes.rect(b);
      const cx = (ra.x + ra.w / 2 + rb.x + rb.w / 2) / 2, cy = (ra.y + ra.h / 2 + rb.y + rb.h / 2) / 2;
      return this.viewport.toScreenRect({ x: cx - 20, y: cy - 10, w: 40, h: 20 });
    }
    const b = this.bounds(this.selection.nodes());
    return b ? this.viewport.toScreenRect(b) : null;
  }

  // ------------------------------------------------ création / suppression
  _centerWorld() {
    const r = this.viewport.rect;
    return this.viewport.toWorld(r.left + r.width / 2, r.top + r.height / 2);
  }

  bringToFront(n) {
    const b = this.board, i = b.nodes.indexOf(n);
    if (n.type !== 'card' || i < 0 || i === b.nodes.length - 1) return;
    b.nodes.splice(i, 1);
    b.nodes.push(n);
    this.nodes.bringToFront(n);
  }

  addCard(x, y, props, edit) {
    const n = newCard(x, y, props);
    this.board.nodes.push(n);
    this.selection.set([n.id]);
    this.render();
    if (edit) this.editor.start(n.id); else this.commit();
    return n;
  }

  addSection() {
    const c = this._centerWorld();
    const n = newSection(c.x - 320, c.y - 190);
    this.board.nodes.unshift(n);
    this.select([n.id]);
    this.commit();
    this.editSectionTitle(n.id);
  }

  addBoardCard() {
    const c = this._centerWorld();
    const n = this.addCard(c.x - 130, c.y - 40, { w: 260, title: 'Nouveau whiteboard' }, false);
    this.makeBoard(n.id);
    this.editor.start(n.id, { focusTitle: true });
  }

  makeBoard(id) {
    const n = this.node(id);
    if (!n || n.childBoardId) return;
    const b = newBoard(n.title || 'Sous-whiteboard', { boardId: this.boardId, nodeId: n.id });
    this.store.addBoard(b);
    n.childBoardId = b.id;
    this.refreshCard(id);
    this.render();
    this.commit();
  }

  _deleteBoardRec(bid) {
    const b = this.store.board(bid);
    if (!b) return;
    for (const n of b.nodes) if (n.childBoardId) this._deleteBoardRec(n.childBoardId);
    delete this.store.doc.boards[bid];
    delete this.store.views[bid];
  }

  /** Retire des nœuds (et leurs flèches, et leurs sous-whiteboards) sans confirmation. */
  removeNodes(ids) {
    const set = new Set(ids), b = this.board;
    for (const n of b.nodes) if (set.has(n.id) && n.childBoardId) this._deleteBoardRec(n.childBoardId);
    b.nodes = b.nodes.filter((n) => !set.has(n.id));
    b.edges = b.edges.filter((e) => !set.has(e.from) && !set.has(e.to));
    for (const id of set) this.selection.delete(id);
  }

  async deleteSelection() {
    const b = this.board;
    if (this.selection.edge) {
      const id = this.selection.edge;
      b.edges = b.edges.filter((e) => e.id !== id);
      this.selection.clear();
      this.render(); this.commit();
      return;
    }
    if (!this.selection.size) return;
    const boards = this.selection.nodes().filter((n) => {
      const cb = n.childBoardId && this.store.board(n.childBoardId);
      return cb && cb.nodes.length;
    });
    if (boards.length) {
      const ok = await this.dialogs.confirm({
        title: 'Supprimer le sous-whiteboard ?',
        message: `« ${cardSummary(boards[0])} »${boards.length > 1 ? ` et ${boards.length - 1} autre(s)` : ''} contient des cartes. Elles seront supprimées aussi.`,
        ok: 'Supprimer', danger: true
      });
      if (!ok) return;
    }
    this.removeNodes(Array.from(this.selection.ids));
    this.render(); this.commit();
  }

  _cloneBoardRec(bid, parent) {
    const src = this.store.board(bid);
    if (!src) return null;
    const b = clone(src), map = {};
    b.id = uid('b');
    b.parent = parent;
    for (const n of b.nodes) { const old = n.id; n.id = uid('n'); map[old] = n.id; }
    for (const e of b.edges) { e.id = uid('e'); e.from = map[e.from]; e.to = map[e.to]; }
    this.store.addBoard(b);
    for (const n of b.nodes) if (n.childBoardId) n.childBoardId = this._cloneBoardRec(n.childBoardId, { boardId: b.id, nodeId: n.id });
    return b.id;
  }

  duplicateSelection() {
    if (!this.selection.size) return;
    const b = this.board, map = {}, added = [];
    for (const n of this.selection.nodes()) {
      const c = clone(n);
      c.id = uid('n'); c.x += 30; c.y += 30;
      if (c.blocks) for (const k of c.blocks) k.id = uid('k');
      map[n.id] = c.id;
      if (c.childBoardId) c.childBoardId = this._cloneBoardRec(n.childBoardId, { boardId: this.boardId, nodeId: c.id });
      added.push(c);
    }
    for (const c of added) { if (c.type === 'section') b.nodes.unshift(c); else b.nodes.push(c); }
    for (const e of b.edges.slice()) {
      if (map[e.from] && map[e.to]) b.edges.push(Object.assign(clone(e), { id: uid('e'), from: map[e.from], to: map[e.to] }));
    }
    this.select(added.map((c) => c.id));
    this.commit();
  }

  setColor(c) {
    const ed = this.selection.edge && this.edge(this.selection.edge);
    if (ed) ed.color = c;
    else for (const n of this.selection.nodes()) n.color = c;
    for (const n of this.selection.nodes()) this.refreshCard(n.id);
    this.render(); this.commit();
  }

  async editEdgeLabel(id) {
    const e = this.edge(id);
    if (!e) return;
    const v = await this.dialogs.prompt({ title: 'Libellé de la flèche', value: e.label || '', placeholder: 'ex. : dépend de' });
    if (v === null || !this.edge(id)) return;
    e.label = v.trim();
    this.render(); this.commit();
  }

  toggleCollapse(id) {
    const n = this.node(id);
    if (!n || n.type !== 'card') return;
    if (this.editor.cardId === id) this.editor.stop();
    n.collapsed = !n.collapsed;
    if (!n.collapsed) delete n.collapsed;
    this.refreshCard(id);
    this.render(); this.commit();
  }

  /** Coche une case ou déplie une liste (cible : .tf-check ou .tf-tog). */
  toggleBlockFlag(target) {
    const be = target.closest('.tf-block'), ce = be && be.closest('.tf-card');
    const n = ce && this.node(ce.dataset.id);
    const b = n && n.blocks.find((x) => x.id === be.dataset.bid);
    if (!b) return;
    if (target.classList.contains('tf-check')) { if (b.checked) delete b.checked; else b.checked = true; }
    else b.open = !b.open;
    this.refreshCard(n.id);
    this.commit();
  }

  /** Insère une image après le bloc `bid` (ou à sa place s'il est vide). */
  addImageBlock(cardId, bid, src, replaceIfEmpty) {
    const n = this.node(cardId);
    if (!n) return;
    const img = nb('image', '', { asset: this.store.addAsset(src) });
    const i = n.blocks.findIndex((x) => x.id === bid);
    if (i < 0) n.blocks.push(img);
    else if (replaceIfEmpty && n.blocks[i].type === 'text' && !n.blocks[i].html) n.blocks.splice(i, 1, img);
    else n.blocks.splice(i + 1, 0, img);
    const j = n.blocks.indexOf(img);
    let after = n.blocks[j + 1];
    const editing = this.editor.cardId === cardId;
    if (editing && (!after || isVoid(after.type))) { after = nb('text'); n.blocks.splice(j + 1, 0, after); }
    this.refreshCard(cardId);
    if (editing && after) this.editor.focusBlock(after.id, 'start'); else this.commit();
  }

  pickImage(cb) {
    this._imgCb = cb;
    this.chrome.fileImage.value = '';
    this.chrome.fileImage.click();
  }

  editSectionTitle(id) {
    const e = this.nodes.get(id), n = this.node(id);
    if (!e || !n) return;
    const lab = e.querySelector('.tf-section-label');
    lab.contentEditable = 'true';
    lab.focus();
    const rg = document.createRange();
    rg.selectNodeContents(lab);
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(rg);
    const done = (cancel) => {
      lab.removeEventListener('blur', onBlur);
      lab.removeEventListener('keydown', onKey);
      lab.contentEditable = 'false';
      if (!cancel) n.title = lab.innerText.replace(/\s+/g, ' ').trim() || 'Section';
      lab.textContent = n.title;
      this.render(); this.commit();
      this.root.focus({ preventScroll: true });
    };
    const onBlur = () => done(false);
    const onKey = (ev) => {
      ev.stopPropagation();
      if (ev.key === 'Enter') { ev.preventDefault(); lab.blur(); }
      if (ev.key === 'Escape') { ev.preventDefault(); lab.removeEventListener('blur', onBlur); done(true); }
    };
    lab.addEventListener('blur', onBlur);
    lab.addEventListener('keydown', onKey);
  }

  // ------------------------------------------------ annulation
  undo() { this._history('undo'); }
  redo() { this._history('redo'); }

  _history(dir) {
    if (this.focus.isOpen) this.focus.close();
    if (this.editor.active) this.editor.stop();
    if (!(dir === 'undo' ? this.store.undo() : this.store.redo())) return;
    this.selection.clear();
    if (!this.board) this.openBoard(this.store.rootBoardId);
    else { this.nodes.clear(); this.edges.clear(); this.render(); this._renderCrumbs(); }
  }

  // ------------------------------------------------ actions des barres
  action(a) {
    const c = this._centerWorld();
    switch (a) {
      case 'add-card': this.addCard(c.x - 140 + (Math.random() * 40 - 20), c.y - 50 + (Math.random() * 40 - 20), null, true); break;
      case 'add-section': this.addSection(); break;
      case 'add-board': this.addBoardCard(); break;
      case 'undo': this.undo(); break;
      case 'redo': this.redo(); break;
      case 'export': this.exportJSON(); break;
      case 'import': this.chrome.fileImport.value = ''; this.chrome.fileImport.click(); break;
      case 'reset': this.resetDemo(); break;
      case 'toggle-map':
        this.minimap.setVisible(!this.minimap.visible);
        prefs.set('map', this.minimap.visible ? '1' : '0');
        this.chrome.mapBtn.classList.toggle('tf-on', this.minimap.visible);
        break;
      case 'toggle-mode': this.setMode(this.mode === 'mouse' ? 'touchpad' : 'mouse'); break;
      case 'toggle-theme': this.setTheme(this.theme === 'dark' ? 'light' : 'dark'); break;
      case 'zoom-in': this.viewport.zoomCenter(1.25); break;
      case 'zoom-out': this.viewport.zoomCenter(0.8); break;
      case 'zoom-reset': this.viewport.zoomCenter(1 / this.viewport.view.z); break;
      case 'fit': this.fit(); break;
    }
  }

  selectionAction(a) {
    const id = this.selection.first(), ed = this.selection.edge && this.edge(this.selection.edge);
    if (a.startsWith('dir-') && ed) { ed.dir = a.slice(4); if (ed.dir === 'end') delete ed.dir; this.render(); this.commit(); return; }
    if (a.startsWith('style-') && ed) { ed.style = a.slice(6); if (ed.style === 'solid') delete ed.style; this.render(); this.commit(); return; }
    switch (a) {
      case 'delete': this.deleteSelection(); break;
      case 'duplicate': this.duplicateSelection(); break;
      case 'to-board': this.makeBoard(id); this.toast('Carte transformée en sous-whiteboard : clique sur « Ouvrir »'); break;
      case 'open': { const n = this.node(id); if (n && n.childBoardId) this.openBoard(n.childBoardId); break; }
      case 'focus': this.focus.open(id); break;
      case 'label': if (ed) this.editEdgeLabel(ed.id); break;
      case 'swap': if (ed) { [ed.from, ed.to] = [ed.to, ed.from]; this.render(); this.commit(); } break;
    }
  }

  setMode(m) {
    this.mode = m;
    prefs.set('mode', m);
    this.root.dataset.mode = m;
    this._renderModeUI();
    this.toast(m === 'mouse'
      ? 'Mode souris : molette pour zoomer, glisser le fond pour se déplacer'
      : 'Mode touchpad : deux doigts pour se déplacer, pincer pour zoomer, glisser pour sélectionner');
  }

  _renderModeUI() {
    const m = this.mode, c = this.chrome;
    c.modeBtn.innerHTML = m === 'mouse' ? I.mouse : I.touchpad;
    c.modeBtn.title = m === 'mouse' ? 'Mode souris (cliquer pour passer en mode touchpad)' : 'Mode touchpad (cliquer pour passer en mode souris)';
    c.hint.textContent = m === 'mouse'
      ? 'Molette : zoom · Glisser le fond : se déplacer · Double-clic : nouvelle carte'
      : 'Deux doigts : se déplacer · Pincer : zoom · Glisser le fond : sélection · Double-clic : nouvelle carte';
    c.mapBtn.classList.toggle('tf-on', prefs.get('map') !== '0');
  }

  setTheme(t) {
    this.theme = t;
    prefs.set('theme', t);
    this.root.dataset.theme = t;
    this._renderThemeUI();
    this.minimap.schedule();
  }

  _renderThemeUI() {
    const dark = this.theme === 'dark', b = this.chrome.themeBtn;
    b.innerHTML = dark ? I.moon : I.sun;
    b.title = dark ? 'Thème sombre (cliquer pour passer en clair)' : 'Thème clair (cliquer pour passer en sombre)';
  }

  // ------------------------------------------------ import / export
  exportJSON() {
    const blob = new Blob([JSON.stringify(this.store.toJSON(), null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'teamflow-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  importJSON(file) {
    const fr = new FileReader();
    fr.onload = () => {
      try {
        const d = JSON.parse(fr.result);
        if (!isValidDoc(d)) throw new Error('format inconnu');
        this._replaceDoc(d);
        this.toast('Whiteboard importé');
      } catch (e) { this.toast('Import impossible : ' + e.message); }
    };
    fr.readAsText(file);
  }

  async resetDemo() {
    const ok = await this.dialogs.confirm({
      title: 'Réinitialiser la démo ?',
      message: 'Tout le contenu actuel sera remplacé par la démo de départ. Pense à exporter si tu veux le garder.',
      ok: 'Réinitialiser', danger: true
    });
    if (ok) this._replaceDoc(demoData());
  }

  _replaceDoc(d) {
    if (this.focus.isOpen) this.focus.close();
    if (this.editor.active) this.editor.stop();
    this.store.setDoc(d);
    this.store._saveSoon();
    this.boardId = null;
    this.openBoard(this.store.rootBoardId);
  }

  // ------------------------------------------------ retours visuels
  toast(msg) {
    const t = this.chrome.toast;
    t.textContent = msg;
    t.classList.add('tf-show');
    clearTimeout(this._toastT);
    this._toastT = setTimeout(() => t.classList.remove('tf-show'), 2600);
  }

  _status(text, err) {
    const s = this.chrome.status;
    s.textContent = text;
    s.classList.toggle('tf-err', !!err);
  }
}
