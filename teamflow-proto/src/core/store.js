/**
 * Store : détient le document, l'historique d'annulation et la sauvegarde.
 * Les images (assets) sont hors historique : un instantané ne contient que la structure.
 */
import { isValidDoc, allCards } from './model.js';
import { migrate, collectAssets } from './migrate.js';
import { demoData } from './demo.js';
import { uid, debounce } from './util.js';

const HISTORY_MAX = 100;

export class Store {
  /** @param {{load():Promise<object|null>, save(doc:object):Promise<void>}} adapter */
  constructor(adapter) {
    this.adapter = adapter;
    this.doc = null;
    this.views = {};
    this.undoStack = [];
    this.redoStack = [];
    this._snap = null;
    this._listeners = new Set();
    this._saveSoon = debounce(() => this._save(), 500);
    this._saveViewsSoon = debounce(() => this._save(true), 800);
  }

  // ------------------------------------------------ événements
  /** fn(type, payload) ; types : 'saving' | 'saved' | 'save-error' | 'reset' */
  on(fn) { this._listeners.add(fn); return () => this._listeners.delete(fn); }
  _emit(type, payload) { this._listeners.forEach((fn) => fn(type, payload)); }

  // ------------------------------------------------ chargement
  async load() {
    let d = null;
    try { d = await this.adapter.load(); } catch (e) { d = null; }
    this.setDoc(isValidDoc(d) ? d : demoData());
  }

  setDoc(d) {
    migrate(d);
    collectAssets(d);
    this.doc = { version: d.version, rootBoardId: d.rootBoardId, boards: d.boards, assets: d.assets };
    this.views = d.views || {};
    this.undoStack = [];
    this.redoStack = [];
    this._snap = this._serialize();
    this._emit('reset');
  }

  toJSON() {
    return { ...this.doc, views: this.views };
  }

  // ------------------------------------------------ accès
  board(id) { return this.doc.boards[id]; }
  get rootBoardId() { return this.doc.rootBoardId; }

  addBoard(board) { this.doc.boards[board.id] = board; }

  /** Retrouve une carte dans n'importe quel whiteboard. */
  findCard(cardId) {
    for (const hit of allCards(this.doc)) if (hit.node.id === cardId) return hit;
    return null;
  }

  cards() { return Array.from(allCards(this.doc)); }

  addAsset(src) {
    const id = uid('a');
    this.doc.assets[id] = { src };
    return id;
  }

  assetUrl(id) {
    const a = this.doc.assets[id];
    return a ? a.src : '';
  }

  // ------------------------------------------------ historique
  _serialize() {
    return JSON.stringify({ rootBoardId: this.doc.rootBoardId, boards: this.doc.boards });
  }

  /** Enregistre l'état courant comme une étape d'annulation. Renvoie false si rien n'a changé. */
  commit() {
    const s = this._serialize();
    if (s === this._snap) return false;
    this.undoStack.push(this._snap);
    if (this.undoStack.length > HISTORY_MAX) this.undoStack.shift();
    this.redoStack = [];
    this._snap = s;
    this._saveSoon();
    return true;
  }

  undo() {
    if (!this.undoStack.length) return false;
    this.redoStack.push(this._snap);
    this._restore(this.undoStack.pop());
    return true;
  }

  redo() {
    if (!this.redoStack.length) return false;
    this.undoStack.push(this._snap);
    this._restore(this.redoStack.pop());
    return true;
  }

  _restore(s) {
    const d = JSON.parse(s);
    this.doc.rootBoardId = d.rootBoardId;
    this.doc.boards = d.boards;
    this._snap = s;
    this._saveSoon();
  }

  // ------------------------------------------------ sauvegarde
  saveViews() { this._saveViewsSoon(); }

  _save(quiet) {
    if (!quiet) this._emit('saving');
    return Promise.resolve()
      .then(() => this.adapter.save(this.toJSON()))
      .then(() => { if (!quiet) this._emit('saved'); })
      .catch((e) => this._emit('save-error', e));
  }
}
