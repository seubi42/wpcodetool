/** Sélection courante : un ensemble de nœuds, ou une flèche. */
export class Selection {
  constructor(app) {
    this.app = app;
    this.ids = new Set();
    this.edge = null;
  }

  get size() { return this.ids.size; }
  has(id) { return this.ids.has(id); }
  first() { return this.ids.values().next().value; }

  set(ids) { this.ids = new Set(ids); this.edge = null; }
  add(id) { this.ids.add(id); this.edge = null; }
  delete(id) { this.ids.delete(id); }
  toggle(id) { if (this.ids.has(id)) this.ids.delete(id); else this.ids.add(id); this.edge = null; }
  setEdge(id) { this.ids.clear(); this.edge = id; }
  clear() { this.ids.clear(); this.edge = null; }
  get empty() { return !this.ids.size && !this.edge; }

  /** Nœuds sélectionnés encore présents dans le whiteboard courant. */
  nodes() {
    return Array.from(this.ids).map((id) => this.app.node(id)).filter(Boolean);
  }
}
