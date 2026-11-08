var TeamFlow = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/index.js
  var src_exports = {};
  __export(src_exports, {
    App: () => App,
    colors: () => COLORS,
    demoData: () => demoData,
    mount: () => mount,
    storage: () => storage,
    version: () => version
  });

  // src/core/util.js
  function uid(prefix) {
    return prefix + "_" + Math.random().toString(36).slice(2, 10);
  }
  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
  }
  var ESC = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ESC[c]);
  }
  function clone(o) {
    return JSON.parse(JSON.stringify(o));
  }
  function debounce(fn, ms) {
    let t = null;
    const d = (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
    d.cancel = () => clearTimeout(t);
    return d;
  }
  function norm(s) {
    return String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }
  var prefs = {
    get(key) {
      try {
        return localStorage.getItem("teamflow:pref:" + key);
      } catch (e) {
        return null;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem("teamflow:pref:" + key, value);
      } catch (e) {
      }
    }
  };

  // src/core/model.js
  var DOC_VERSION = 3;
  function newBoard(title, parent) {
    return { id: uid("b"), title: title || "Sans titre", parent: parent || null, nodes: [], edges: [] };
  }
  function newCard(x, y, props) {
    return Object.assign({ id: uid("n"), type: "card", x: Math.round(x), y: Math.round(y), w: 280, color: "default", title: "", blocks: [] }, props);
  }
  function newSection(x, y, props) {
    return Object.assign({ id: uid("n"), type: "section", x: Math.round(x), y: Math.round(y), w: 640, h: 380, color: "gray", title: "Nouvelle section" }, props);
  }
  function newEdge(from, to, props) {
    return Object.assign({ id: uid("e"), from, to, label: "", color: "default" }, props);
  }
  function nb(type, html, extra) {
    return Object.assign({ id: uid("k"), type, html: html || "" }, extra);
  }
  function isValidDoc(d) {
    return !!(d && typeof d === "object" && d.boards && d.rootBoardId && d.boards[d.rootBoardId]);
  }
  function* allCards(doc) {
    for (const id of Object.keys(doc.boards)) {
      const board = doc.boards[id];
      for (const node of board.nodes) if (node.type === "card") yield { board, node };
    }
  }

  // src/blocks/sanitize.js
  var INLINE_OK = { B: 1, STRONG: 1, I: 1, EM: 1, S: 1, STRIKE: 1, DEL: 1, U: 1, CODE: 1, A: 1, BR: 1, SPAN: 1 };
  function unwrap(n) {
    const frag = document.createDocumentFragment();
    while (n.firstChild) frag.appendChild(n.firstChild);
    n.replaceWith(frag);
  }
  function sanitizeInline(html) {
    const t = document.createElement("template");
    t.innerHTML = html || "";
    (function walk(parent) {
      for (const n of Array.from(parent.childNodes)) {
        if (n.nodeType === 8) {
          n.remove();
          continue;
        }
        if (n.nodeType !== 1) continue;
        if (n.tagName === "SPAN" && n.classList.contains("tf-mention") && n.dataset.card) {
          const id = n.dataset.card;
          for (const a of Array.from(n.attributes)) n.removeAttribute(a.name);
          n.className = "tf-mention";
          n.dataset.card = id;
          continue;
        }
        walk(n);
        if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|IMG|SVG|TEMPLATE)$/i.test(n.tagName)) {
          n.remove();
          continue;
        }
        if (!INLINE_OK[n.tagName] || n.tagName === "SPAN") {
          unwrap(n);
          continue;
        }
        for (const a of Array.from(n.attributes)) {
          if (!(n.tagName === "A" && a.name === "href")) n.removeAttribute(a.name);
        }
        if (n.tagName === "A") {
          if (/^\s*javascript:/i.test(n.getAttribute("href") || "")) n.removeAttribute("href");
          n.setAttribute("target", "_blank");
          n.setAttribute("rel", "noopener");
        }
      }
    })(t.content);
    return t.innerHTML.replace(/(<br>)+$/, "");
  }
  function htmlToBlocks(html) {
    const t = document.createElement("template");
    t.innerHTML = html || "";
    const out = [];
    for (const n of Array.from(t.content.childNodes)) {
      if (n.nodeType === 3) {
        if (n.textContent.trim()) out.push(nb("text", esc(n.textContent.trim())));
        continue;
      }
      if (n.nodeType !== 1) continue;
      const tag = n.tagName;
      if (/^H[1-3]$/.test(tag)) out.push(nb(tag.toLowerCase(), sanitizeInline(n.innerHTML)));
      else if (tag === "UL" || tag === "OL") n.querySelectorAll(":scope > li").forEach((li) => out.push(nb(tag === "UL" ? "bullet" : "number", sanitizeInline(li.innerHTML))));
      else if (tag === "PRE") out.push(nb("code", "", { text: n.textContent }));
      else if (tag === "IMG") out.push(nb("image", "", { src: n.getAttribute("src") }));
      else if (tag === "HR") out.push(nb("divider"));
      else if (tag === "BLOCKQUOTE") out.push(nb("quote", sanitizeInline(n.innerHTML)));
      else {
        n.querySelectorAll("img").forEach((i) => {
          out.push(nb("image", "", { src: i.getAttribute("src") }));
          i.remove();
        });
        if (n.textContent.trim()) out.push(nb("text", sanitizeInline(n.innerHTML)));
      }
    }
    return out;
  }

  // src/core/migrate.js
  function migrate(doc) {
    doc.assets = doc.assets || {};
    for (const id of Object.keys(doc.boards)) {
      for (const n of doc.boards[id].nodes) {
        if (n.type !== "card") continue;
        if (!Array.isArray(n.blocks)) {
          n.blocks = htmlToBlocks(n.html);
          delete n.html;
        }
        for (const b of n.blocks) {
          if (b.type === "image" && b.src && b.src.startsWith("data:")) {
            const aid = uid("a");
            doc.assets[aid] = { src: b.src };
            b.asset = aid;
            delete b.src;
          }
        }
      }
    }
    doc.version = DOC_VERSION;
    return doc;
  }
  function collectAssets(doc) {
    const used = /* @__PURE__ */ new Set();
    for (const id of Object.keys(doc.boards)) {
      for (const n of doc.boards[id].nodes) {
        if (n.type === "card") {
          for (const b of n.blocks) if (b.asset) used.add(b.asset);
        }
      }
    }
    for (const aid of Object.keys(doc.assets)) if (!used.has(aid)) delete doc.assets[aid];
  }

  // src/core/demo.js
  function demoData() {
    const root = newBoard("Tableau principal");
    const wp = newBoard("Int\xE9gration WordPress");
    const sec = newBoard("S\xE9curit\xE9");
    const boards = {};
    boards[root.id] = root;
    boards[wp.id] = wp;
    boards[sec.id] = sec;
    const T = (h) => nb("text", h);
    const welcome = newCard(-440, 20, {
      w: 350,
      color: "green",
      title: "Bienvenue dans TeamFlow",
      blocks: [
        T("Un d\xE9monstrateur de whiteboard : des cartes faites de <b>blocs</b>, reli\xE9es par des fl\xE8ches."),
        nb("h3", "Pour commencer"),
        nb("bullet", "<b>Double-clic</b> sur le fond : nouvelle carte"),
        nb("bullet", "<b>Double-clic</b> sur une carte : la modifier"),
        nb("bullet", "Tape <code>/</code> dans une carte pour ins\xE9rer un bloc, <code>@</code> pour lier une autre carte"),
        nb("bullet", "Attrape la <b>poign\xE9e \u283F</b> \xE0 gauche d'un bloc pour le d\xE9placer, m\xEAme vers une autre carte"),
        nb("bullet", "Tire un <b>point bleu</b> au bord d'une carte pour cr\xE9er une fl\xE8che"),
        nb("callout", "Les cartes avec l'ic\xF4ne \u25A6 ouvrent un <b>sous-whiteboard</b>.")
      ]
    });
    const s1 = newSection(0, 0, { w: 960, h: 460, color: "yellow", title: "\u2B50 Lancement du projet" });
    const vision = newCard(40, 50, {
      w: 280,
      title: "Vision",
      blocks: [
        T("Un espace visuel pour organiser projets et id\xE9es."),
        nb("quote", "Des cartes libres, reli\xE9es entre elles, et imbriqu\xE9es \xE0 volont\xE9.")
      ]
    });
    const demo = newCard(360, 50, {
      w: 280,
      color: "blue",
      title: "D\xE9monstrateur JS",
      blocks: [
        nb("todo", "Canevas infini, zoom &amp; minimap", { checked: true }),
        nb("todo", "Fl\xE8ches et sous-whiteboards", { checked: true }),
        nb("todo", "Cartes en blocs + menu /", { checked: true }),
        nb("todo", "Glisser des blocs entre cartes", { checked: true }),
        nb("todo", "Tags et recherche")
      ]
    });
    const wpCard = newCard(680, 50, {
      w: 240,
      color: "purple",
      title: "Int\xE9gration WordPress",
      childBoardId: wp.id,
      blocks: [T("Plus tard : int\xE9grer TeamFlow dans l'admin WordPress.")]
    });
    const questions = newCard(360, 270, {
      w: 280,
      color: "orange",
      title: "Questions ouvertes",
      blocks: [
        nb("number", "Usage perso ou collaboratif ?"),
        nb("number", "O\xF9 stocker les donn\xE9es ?"),
        nb("toggle", "Pistes de stockage", { open: false, body: "localStorage pour la d\xE9mo, puis API REST WordPress." })
      ]
    });
    const s2 = newSection(0, 560, { w: 960, h: 340, color: "blue", title: "\u{1F4A1} Id\xE9es en vrac" });
    const nav = newCard(40, 610, {
      w: 280,
      title: "Modes de navigation",
      blocks: [
        nb("h3", "Souris"),
        T("Molette = zoom, glisser le fond = se d\xE9placer."),
        nb("h3", "Touchpad"),
        T("Deux doigts = se d\xE9placer, pincer = zoomer, glisser le fond = s\xE9lection."),
        nb("divider"),
        T("Le bouton en bas \xE0 droite bascule entre les deux.")
      ]
    });
    const keys = newCard(360, 610, {
      w: 280,
      title: "Raccourcis",
      blocks: [
        nb("bullet", "<b>/</b> : menu des blocs"),
        nb("bullet", "<b># </b>, <b>## </b>, <b>- </b>, <b>1. </b>, <b>[] </b>, <b>&gt; </b> : blocs en Markdown"),
        nb("bullet", "<b>Tab</b> : indenter une liste"),
        nb("bullet", "<b>Ctrl+Z / Ctrl+Y</b> : annuler / r\xE9tablir"),
        nb("bullet", "<b>Ctrl+D</b> : dupliquer"),
        nb("bullet", "<b>\xC9chap</b> : terminer, puis remonter d'un niveau")
      ]
    });
    const ideas = newCard(680, 610, {
      w: 240,
      color: "pink",
      title: "Pistes",
      blocks: [nb("bullet", "Tags et recherche"), nb("bullet", "Export en image"), nb("bullet", "\xC9dition \xE0 plusieurs")]
    });
    root.nodes.push(s1, s2, welcome, vision, demo, wpCard, questions, nav, keys, ideas);
    root.edges.push(
      newEdge(vision.id, demo.id),
      newEdge(demo.id, wpCard.id, { label: "ensuite" }),
      newEdge(demo.id, questions.id, { label: "\xE0 trancher", color: "orange" })
    );
    wp.parent = { boardId: root.id, nodeId: wpCard.id };
    const admin = newCard(0, 0, {
      color: "purple",
      title: "Page d'admin",
      blocks: [T("Une page sous le menu WordPress qui charge <code>teamflow.js</code> et appelle <code>TeamFlow.mount()</code>.")]
    });
    const api = newCard(380, 0, {
      color: "blue",
      title: "API REST",
      blocks: [
        nb("code", "", { text: "register_rest_route(\n  'teamflow/v1',\n  '/boards/(?P<id>\\d+)'\n);" }),
        T("Adaptateur pr\xEAt : <code>TeamFlow.storage.rest()</code>")
      ]
    });
    const store = newCard(760, 0, {
      title: "Stockage",
      blocks: [T("Un type de contenu \xAB teamflow_board \xBB, le JSON du whiteboard en post meta.")]
    });
    const secCard = newCard(380, 280, {
      color: "red",
      title: "S\xE9curit\xE9",
      childBoardId: sec.id,
      blocks: [T("Droits et nonces, d\xE9taill\xE9s dans ce sous-whiteboard.")]
    });
    wp.nodes.push(admin, api, store, secCard);
    wp.edges.push(newEdge(admin.id, api.id, { label: "fetch" }), newEdge(api.id, store.id), newEdge(api.id, secCard.id, { color: "red" }));
    sec.parent = { boardId: wp.id, nodeId: secCard.id };
    const c1 = newCard(0, 0, { title: "Capacit\xE9s", blocks: [T("V\xE9rifier <code>current_user_can('edit_posts')</code> dans chaque route.")] });
    const c2 = newCard(360, 0, { title: "Nonce", blocks: [T("Envoyer l'en-t\xEAte <code>X-WP-Nonce</code> (wp_rest).")] });
    sec.nodes.push(c1, c2);
    sec.edges.push(newEdge(c1.id, c2.id));
    return { version: DOC_VERSION, rootBoardId: root.id, boards, assets: {}, views: {} };
  }

  // src/core/store.js
  var HISTORY_MAX = 100;
  var Store = class {
    /** @param {{load():Promise<object|null>, save(doc:object):Promise<void>}} adapter */
    constructor(adapter) {
      this.adapter = adapter;
      this.doc = null;
      this.views = {};
      this.undoStack = [];
      this.redoStack = [];
      this._snap = null;
      this._listeners = /* @__PURE__ */ new Set();
      this._saveSoon = debounce(() => this._save(), 500);
      this._saveViewsSoon = debounce(() => this._save(true), 800);
    }
    // ------------------------------------------------ événements
    /** fn(type, payload) ; types : 'saving' | 'saved' | 'save-error' | 'reset' */
    on(fn) {
      this._listeners.add(fn);
      return () => this._listeners.delete(fn);
    }
    _emit(type, payload) {
      this._listeners.forEach((fn) => fn(type, payload));
    }
    // ------------------------------------------------ chargement
    async load() {
      let d = null;
      try {
        d = await this.adapter.load();
      } catch (e) {
        d = null;
      }
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
      this._emit("reset");
    }
    toJSON() {
      return { ...this.doc, views: this.views };
    }
    // ------------------------------------------------ accès
    board(id) {
      return this.doc.boards[id];
    }
    get rootBoardId() {
      return this.doc.rootBoardId;
    }
    addBoard(board) {
      this.doc.boards[board.id] = board;
    }
    /** Retrouve une carte dans n'importe quel whiteboard. */
    findCard(cardId) {
      for (const hit of allCards(this.doc)) if (hit.node.id === cardId) return hit;
      return null;
    }
    cards() {
      return Array.from(allCards(this.doc));
    }
    addAsset(src) {
      const id = uid("a");
      this.doc.assets[id] = { src };
      return id;
    }
    assetUrl(id) {
      const a = this.doc.assets[id];
      return a ? a.src : "";
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
    saveViews() {
      this._saveViewsSoon();
    }
    _save(quiet) {
      if (!quiet) this._emit("saving");
      return Promise.resolve().then(() => this.adapter.save(this.toJSON())).then(() => {
        if (!quiet) this._emit("saved");
      }).catch((e) => this._emit("save-error", e));
    }
  };

  // src/core/selection.js
  var Selection = class {
    constructor(app) {
      this.app = app;
      this.ids = /* @__PURE__ */ new Set();
      this.edge = null;
    }
    get size() {
      return this.ids.size;
    }
    has(id) {
      return this.ids.has(id);
    }
    first() {
      return this.ids.values().next().value;
    }
    set(ids) {
      this.ids = new Set(ids);
      this.edge = null;
    }
    add(id) {
      this.ids.add(id);
      this.edge = null;
    }
    delete(id) {
      this.ids.delete(id);
    }
    toggle(id) {
      if (this.ids.has(id)) this.ids.delete(id);
      else this.ids.add(id);
      this.edge = null;
    }
    setEdge(id) {
      this.ids.clear();
      this.edge = id;
    }
    clear() {
      this.ids.clear();
      this.edge = null;
    }
    get empty() {
      return !this.ids.size && !this.edge;
    }
    /** Nœuds sélectionnés encore présents dans le whiteboard courant. */
    nodes() {
      return Array.from(this.ids).map((id) => this.app.node(id)).filter(Boolean);
    }
  };

  // src/ui/dom.js
  function el(tag, cls, html) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function svgEl(tag, attrs) {
    const e = document.createElementNS("http://www.w3.org/2000/svg", tag);
    if (attrs) for (const k of Object.keys(attrs)) e.setAttribute(k, attrs[k]);
    return e;
  }

  // src/ui/icons.js
  var I = {
    mouse: '<svg viewBox="0 0 24 24"><rect x="6" y="3" width="12" height="18" rx="6"/><path d="M12 7v4"/></svg>',
    touchpad: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="12" rx="2"/><path d="M3 20h18"/><path d="M12 17v3"/></svg>',
    plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    minus: '<svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg>',
    fit: '<svg viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
    map: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><rect x="11" y="11" width="7" height="6" rx="1"/></svg>',
    board: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><rect x="6.5" y="7" width="5" height="4" rx="1"/><rect x="13" y="12" width="5" height="5" rx="1"/><path d="M11.5 9h3v3"/></svg>',
    card: '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h6"/></svg>',
    section: '<svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2" stroke-dasharray="3 2"/><rect x="3" y="3" width="9" height="4" rx="1.5"/></svg>',
    undo: '<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>',
    redo: '<svg viewBox="0 0 24 24"><path d="M15 14l5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/></svg>',
    download: '<svg viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>',
    upload: '<svg viewBox="0 0 24 24"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>',
    trash: '<svg viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>',
    copy: '<svg viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg>',
    open: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    swap: '<svg viewBox="0 0 24 24"><path d="M7 7h13M16 3l4 4-4 4M17 17H4M8 13l-4 4 4 4"/></svg>',
    label: '<svg viewBox="0 0 24 24"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>',
    reset: '<svg viewBox="0 0 24 24"><path d="M4 4v6h6"/><path d="M5 15a8 8 0 1 0 2-8.5L4 10"/></svg>',
    image: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></svg>',
    link: '<svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
    ul: '<svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/></svg>',
    ol: '<svg viewBox="0 0 24 24"><path d="M10 6h10M10 12h10M10 18h10"/><path d="M4 4.5h1.5V9M4 9h3M4 14.5c.5-.7 2.7-.8 2.7.6 0 1-2.7 2-2.7 3.4h3"/></svg>',
    code: '<svg viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>',
    sun: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    moon: '<svg viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>',
    help: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17.5v.01"/></svg>',
    chevron: '<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>',
    expand: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>',
    close: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    title: '<svg viewBox="0 0 24 24"><path d="M5 6V4h14v2M12 4v16M9 20h6"/></svg>',
    solid: '<svg viewBox="0 0 24 24"><path d="M4 12h16"/></svg>',
    dashed: '<svg viewBox="0 0 24 24"><path d="M3 12h4M10 12h4M17 12h4"/></svg>'
  };
  var DIR_ICONS = (() => {
    const line = '<path d="M5 12h14"/>', l = '<path d="M9 8l-4 4 4 4"/>', r = '<path d="M15 8l4 4-4 4"/>';
    const w = (x) => '<svg viewBox="0 0 24 24">' + x + "</svg>";
    return { start: w(line + l), none: w(line), end: w(line + r), both: w(line + l + r) };
  })();

  // src/ui/chrome.js
  var btn = (act, icon, label, title) => `<button class="tf-btn" data-act="${act}"${title ? ` title="${esc(title)}"` : ""}>${icon}${label ? `<span>${esc(label)}</span>` : ""}</button>`;
  function buildChrome(root) {
    const top = el("div", "tf-ui tf-topbar");
    top.innerHTML = `<div class="tf-brand"><span class="tf-logo">${I.board}</span>TeamFlow</div><nav class="tf-crumbs"></nav><div class="tf-spacer"></div><span class="tf-status"></span><div class="tf-group">` + btn("add-card", I.card, "Carte", "Nouvelle carte") + btn("add-section", I.section, "Section", "Nouvelle section") + btn("add-board", I.board, "Whiteboard", "Nouvelle carte sous-whiteboard") + '</div><div class="tf-group">' + btn("undo", I.undo, "", "Annuler (Ctrl+Z)") + btn("redo", I.redo, "", "R\xE9tablir (Ctrl+Y)") + '</div><div class="tf-group">' + btn("export", I.download, "", "Exporter en JSON") + btn("import", I.upload, "", "Importer un JSON") + btn("reset", I.reset, "", "R\xE9initialiser la d\xE9mo") + "</div>";
    root.appendChild(top);
    const br = el("div", "tf-ui tf-br");
    const minimapWrap = el("div", "tf-minimap");
    br.appendChild(minimapWrap);
    const ctr = el("div", "tf-controls");
    ctr.innerHTML = btn("toggle-theme", "", "", "") + btn("toggle-map", I.map, "", "Afficher / masquer la vue d'ensemble") + btn("toggle-mode", "", "", "") + '<i class="tf-sep"></i>' + btn("zoom-out", I.minus, "", "D\xE9zoomer (-)") + '<button class="tf-btn tf-zoom" data-act="zoom-reset" title="Revenir \xE0 100 % (0)">100%</button>' + btn("zoom-in", I.plus, "", "Zoomer (+)") + btn("fit", I.fit, "", "Tout afficher (Maj+1)");
    br.appendChild(ctr);
    root.appendChild(br);
    const hint = el("div", "tf-ui tf-hint");
    root.appendChild(hint);
    const toast = el("div", "tf-toast");
    root.appendChild(toast);
    const fileImport = el("input");
    fileImport.type = "file";
    fileImport.accept = "application/json,.json";
    fileImport.hidden = true;
    const fileImage = el("input");
    fileImage.type = "file";
    fileImage.accept = "image/*";
    fileImage.hidden = true;
    root.appendChild(fileImport);
    root.appendChild(fileImage);
    return {
      crumbs: top.querySelector(".tf-crumbs"),
      status: top.querySelector(".tf-status"),
      minimapWrap,
      zoomLabel: ctr.querySelector(".tf-zoom"),
      modeBtn: ctr.querySelector('[data-act="toggle-mode"]'),
      mapBtn: ctr.querySelector('[data-act="toggle-map"]'),
      themeBtn: ctr.querySelector('[data-act="toggle-theme"]'),
      hint,
      toast,
      fileImport,
      fileImage
    };
  }

  // src/ui/menu.js
  var Menu = class {
    constructor(root) {
      this.root = root;
      this.current = null;
    }
    get isOpen() {
      return !!this.current;
    }
    /**
     * @param {Array<{id?, label, icon?, hint?, keys?, group?, on?, danger?}>} items  (`group` = intitulé de section)
     * @param {DOMRect|null} rect  ancre à l'écran
     * @param {(item, meta) => void} onPick
     * @param {{header?: string, empty?: string, meta?: any, filterable?: boolean}} [opts]
     */
    open(items, rect, onPick, opts = {}) {
      this.close();
      const m = el("div", "tf-ui tf-menu");
      const rr = this.root.getBoundingClientRect();
      const state = { filter: "", active: 0, list: [] };
      const render = () => {
        const f = norm(state.filter);
        const shown = items.filter((it) => it.group ? !f : !f || norm(it.label + " " + (it.hint || "") + " " + (it.keys || "")).includes(f));
        state.list = shown.filter((it) => !it.group);
        if (state.active >= state.list.length) state.active = 0;
        let html = opts.header ? `<div class="tf-menu-head">${f ? "Filtre : <b>" + esc(state.filter) + "</b>" : esc(opts.header)}</div>` : "";
        if (!state.list.length) html += `<div class="tf-menu-empty">${esc(opts.empty || "Aucun r\xE9sultat")}</div>`;
        let k = 0;
        for (const it of shown) {
          if (it.group) {
            html += `<div class="tf-menu-group">${esc(it.group)}</div>`;
            continue;
          }
          html += `<div class="tf-menu-item${k === state.active ? " tf-active" : ""}${it.danger ? " tf-danger" : ""}" data-i="${k}"><span class="tf-menu-ic">${it.icon || ""}</span><span class="tf-menu-label">${esc(it.label)}` + (it.hint ? `<small>${esc(it.hint)}</small>` : "") + "</span>" + (it.on ? '<span class="tf-menu-check">\u2713</span>' : "") + "</div>";
          k++;
        }
        m.innerHTML = html;
        const a = m.querySelector(".tf-active");
        if (a && a.scrollIntoView) a.scrollIntoView({ block: "nearest" });
      };
      const pick = (i) => {
        const it = state.list[i];
        if (!it) return;
        const meta = this.current && this.current.meta;
        this.close();
        onPick(it, meta);
      };
      m.addEventListener("mousedown", (e) => e.preventDefault());
      m.addEventListener("pointerdown", (e) => e.stopPropagation());
      m.addEventListener("wheel", (e) => e.stopPropagation(), { passive: true });
      m.addEventListener("click", (e) => {
        const it = e.target.closest(".tf-menu-item");
        if (it) pick(+it.dataset.i);
      });
      m.addEventListener("mousemove", (e) => {
        const it = e.target.closest(".tf-menu-item");
        if (it && +it.dataset.i !== state.active) {
          state.active = +it.dataset.i;
          m.querySelectorAll(".tf-menu-item").forEach((x2) => x2.classList.toggle("tf-active", +x2.dataset.i === state.active));
        }
      });
      this.root.appendChild(m);
      render();
      let x = (rect ? rect.left : rr.left + rr.width / 2 - 130) - rr.left;
      let y = (rect ? rect.bottom + 6 : rr.top + 120) - rr.top;
      const mh = m.offsetHeight, mw = m.offsetWidth;
      if (rect && y + mh > rr.height - 10) y = Math.max(10, rect.top - rr.top - mh - 6);
      m.style.left = clamp(x, 10, rr.width - mw - 10) + "px";
      m.style.top = y + "px";
      this.current = {
        el: m,
        meta: opts.meta,
        setFilter: (f) => {
          state.filter = f;
          state.active = 0;
          render();
        },
        key: (e) => {
          const len = Math.max(1, state.list.length);
          if (e.key === "ArrowDown") {
            e.preventDefault();
            state.active = (state.active + 1) % len;
            render();
            return true;
          }
          if (e.key === "ArrowUp") {
            e.preventDefault();
            state.active = (state.active - 1 + len) % len;
            render();
            return true;
          }
          if (e.key === "Enter" || e.key === "Tab") {
            if (!state.list.length) {
              this.close();
              return false;
            }
            e.preventDefault();
            pick(state.active);
            return true;
          }
          if (e.key === "Escape") {
            e.preventDefault();
            this.close();
            return true;
          }
          return false;
        }
      };
      return this.current;
    }
    /** Transmet une touche au menu ouvert ; renvoie true si elle a été consommée. */
    key(e) {
      return this.current ? this.current.key(e) : false;
    }
    setFilter(f) {
      if (this.current) this.current.setFilter(f);
    }
    get meta() {
      return this.current ? this.current.meta : null;
    }
    close() {
      if (this.current) {
        this.current.el.remove();
        this.current = null;
      }
    }
  };

  // src/ui/dialogs.js
  var Dialogs = class {
    constructor(root) {
      this.root = root;
      this.open = null;
    }
    get isOpen() {
      return !!this.open;
    }
    /** @returns {Promise<boolean>} */
    confirm({ title, message = "", ok = "Confirmer", cancel = "Annuler", danger = false }) {
      return this._show({ title, message, ok, cancel, danger }).then((r) => r !== null);
    }
    /** @returns {Promise<string|null>} null si annulé */
    prompt({ title, message = "", value = "", placeholder = "", ok = "Valider", cancel = "Annuler" }) {
      return this._show({ title, message, ok, cancel, input: { value, placeholder } });
    }
    _show(o) {
      if (this.open) this.open.finish(null);
      const prevFocus = document.activeElement;
      const back = el("div", "tf-modal-back");
      back.innerHTML = `<div class="tf-modal" role="dialog" aria-modal="true"><div class="tf-modal-title">${esc(o.title)}</div>` + (o.message ? `<div class="tf-modal-msg">${esc(o.message)}</div>` : "") + (o.input ? `<input class="tf-modal-input" type="text" placeholder="${esc(o.input.placeholder)}">` : "") + `<div class="tf-modal-actions"><button class="tf-btn tf-modal-cancel">${esc(o.cancel)}</button><button class="tf-btn tf-modal-ok${o.danger ? " tf-danger-solid" : ""}">${esc(o.ok)}</button></div></div>`;
      const input = back.querySelector(".tf-modal-input");
      if (input) input.value = o.input.value;
      this.root.appendChild(back);
      return new Promise((resolve) => {
        const finish = (v) => {
          if (!this.open) return;
          this.open = null;
          back.remove();
          if (prevFocus && prevFocus.focus) prevFocus.focus({ preventScroll: true });
          resolve(v);
        };
        this.open = { finish };
        const okValue = () => input ? input.value : true;
        back.addEventListener("pointerdown", (e) => {
          e.stopPropagation();
          if (e.target === back) finish(null);
        });
        back.addEventListener("wheel", (e) => e.stopPropagation(), { passive: true });
        back.addEventListener("keydown", (e) => {
          e.stopPropagation();
          if (e.key === "Escape") {
            e.preventDefault();
            finish(null);
          }
          if (e.key === "Enter") {
            e.preventDefault();
            finish(okValue());
          }
        });
        back.querySelector(".tf-modal-cancel").addEventListener("click", () => finish(null));
        back.querySelector(".tf-modal-ok").addEventListener("click", () => finish(okValue()));
        requestAnimationFrame(() => {
          if (input) {
            input.focus();
            input.select();
          } else back.querySelector(".tf-modal-ok").focus();
        });
      });
    }
  };

  // src/core/constants.js
  var COLORS = {
    default: null,
    gray: "#8b8d98",
    red: "#e5484d",
    orange: "#f76b15",
    yellow: "#e9b308",
    green: "#30a46c",
    teal: "#12a594",
    blue: "#3e7bfa",
    purple: "#8e4ec6",
    pink: "#d6409f"
  };
  var COLOR_LABELS = {
    default: "Par d\xE9faut",
    gray: "Gris",
    red: "Rouge",
    orange: "Orange",
    yellow: "Jaune",
    green: "Vert",
    teal: "Turquoise",
    blue: "Bleu",
    purple: "Violet",
    pink: "Rose"
  };
  var EDGE_DEFAULT_COLOR = "#8a8f99";
  var ZOOM_MIN = 0.1;
  var ZOOM_MAX = 3;

  // src/ui/toolbars.js
  var TOP_MIN = 60;
  var Toolbars = class {
    constructor(app) {
      this.app = app;
      this.sel = el("div", "tf-ui tf-floatbar tf-selbar");
      this.rt = el("div", "tf-ui tf-floatbar tf-rtbar");
      const rtb = (cmd, inner, title) => `<button class="tf-btn" data-rt="${cmd}" title="${esc(title)}">${inner}</button>`;
      this.rt.innerHTML = `<button class="tf-btn" data-rt="insert" title="Ins\xE9rer un bloc (ou tape \xAB / \xBB)">${I.plus}<span>Bloc</span></button><i class="tf-sep"></i>` + rtb("bold", "<b>B</b>", "Gras (Ctrl+B)") + rtb("italic", "<i>I</i>", "Italique (Ctrl+I)") + rtb("strike", "<s>S</s>", "Barr\xE9") + rtb("icode", I.code, "Code dans le texte") + rtb("link", I.link, "Lien web") + rtb("mention", "<b>@</b>", "Lien vers une carte (ou tape \xAB @ \xBB)") + '<i class="tf-sep"></i>' + rtb("image", I.image, "Image") + '<i class="tf-sep"></i><button class="tf-btn tf-done" data-rt="done">Terminer</button>';
      app.root.appendChild(this.sel);
      app.root.appendChild(this.rt);
      this.rt.addEventListener("mousedown", (e) => {
        const b = e.target.closest("[data-rt]");
        if (!b) return;
        e.preventDefault();
        app.editor.command(b.dataset.rt);
      });
      this.sel.addEventListener("click", (e) => {
        const sw = e.target.closest("[data-color]");
        if (sw) return app.setColor(sw.dataset.color);
        const b = e.target.closest("[data-sel]");
        if (b) app.selectionAction(b.dataset.sel);
      });
    }
    buttonRect(cmd) {
      const b = this.rt.querySelector(`[data-rt="${cmd}"]`);
      return b ? b.getBoundingClientRect() : null;
    }
    render() {
      const app = this.app, editing = app.editor.active;
      this.rt.classList.toggle("tf-show", editing);
      if (editing) {
        this.sel.classList.remove("tf-show");
        this.position();
        return;
      }
      const swatches = (cur) => Object.keys(COLORS).map((c) => `<button class="tf-swatch${c === cur ? " tf-on" : ""}" data-color="${c}" title="${COLOR_LABELS[c]}" style="--c:${COLORS[c] || "var(--tf-neutral)"}"></button>`).join("");
      const btn2 = (act, icon, label, title, extra = "") => `<button class="tf-btn${extra}" data-sel="${act}" title="${esc(title)}">${icon}${label ? `<span>${esc(label)}</span>` : ""}</button>`;
      let html = "";
      const ed = app.selection.edge && app.edge(app.selection.edge);
      if (ed) {
        const dir = ed.dir || "end", style = ed.style || "solid";
        html = swatches(ed.color) + '<i class="tf-sep"></i>' + btn2("label", I.label, "Libell\xE9", "Libell\xE9 de la fl\xE8che") + '<i class="tf-sep"></i>' + [["start", "Fl\xE8che vers le d\xE9part"], ["none", "Sans fl\xE8che"], ["end", "Fl\xE8che vers l'arriv\xE9e"], ["both", "Fl\xE8che dans les deux sens"]].map(([d, t]) => btn2("dir-" + d, DIR_ICONS[d], "", t, dir === d ? " tf-toggle tf-on" : " tf-toggle")).join("") + '<i class="tf-sep"></i>' + btn2("style-solid", I.solid, "", "Trait plein", style === "solid" ? " tf-toggle tf-on" : " tf-toggle") + btn2("style-dashed", I.dashed, "", "Trait pointill\xE9", style === "dashed" ? " tf-toggle tf-on" : " tf-toggle") + '<i class="tf-sep"></i>' + btn2("swap", I.swap, "", "Inverser le d\xE9part et l'arriv\xE9e") + btn2("delete", I.trash, "", "Supprimer (Suppr)", " tf-danger");
      } else if (app.selection.size) {
        const nodes = app.selection.nodes();
        if (nodes.length) {
          const cur = nodes.every((n) => n.color === nodes[0].color) ? nodes[0].color : null;
          if (nodes.length > 1) html += `<span class="tf-selcount">${nodes.length} \xE9l\xE9ments</span><i class="tf-sep"></i>`;
          html += swatches(cur) + '<i class="tf-sep"></i>';
          if (nodes.length === 1 && nodes[0].type === "card") {
            const n = nodes[0];
            html += n.childBoardId ? btn2("open", I.open, "Ouvrir", "Ouvrir le sous-whiteboard") : btn2("to-board", I.board, "En whiteboard", "Transformer cette carte en sous-whiteboard");
            html += btn2("focus", I.expand, "", "Plein \xE9cran");
          }
          html += btn2("duplicate", I.copy, "", "Dupliquer (Ctrl+D)") + btn2("delete", I.trash, "", "Supprimer (Suppr)", " tf-danger");
        }
      }
      this.sel.innerHTML = html;
      this.sel.classList.toggle("tf-show", !!html);
      this.position();
    }
    /** Replace les barres visibles au-dessus de leur cible. */
    position() {
      const app = this.app;
      if (this.rt.classList.contains("tf-show")) {
        const host = app.editor.host;
        this._place(this.rt, app.focus.isOpen ? null : host && host.getBoundingClientRect());
      }
      if (this.sel.classList.contains("tf-show")) this._place(this.sel, app.selectionScreenRect());
    }
    _place(bar, rect) {
      const rr = this.app.root.getBoundingClientRect();
      const bw = bar.offsetWidth, bh = bar.offsetHeight, m = 10;
      let x, y;
      if (!rect) {
        x = (rr.width - bw) / 2;
        y = TOP_MIN;
      } else {
        x = rect.left + rect.width / 2 - bw / 2 - rr.left;
        y = rect.top - rr.top - bh - 14;
        if (y < TOP_MIN) {
          const below = rect.bottom - rr.top + 14;
          y = below + bh < rr.height - 70 ? below : TOP_MIN;
        }
      }
      bar.style.left = clamp(x, m, Math.max(m, rr.width - bw - m)) + "px";
      bar.style.top = Math.max(TOP_MIN, y) + "px";
    }
  };

  // src/ui/keyboard.js
  function bindKeyboard(app) {
    window.addEventListener("keydown", (e) => {
      if (!app.isActive() || app.dialogs.isOpen) return;
      if (app.menu.key(e)) return;
      const mod = e.ctrlKey || e.metaKey;
      if (app.editor.active) {
        if (e.key === "Escape" || mod && e.key === "Enter") {
          e.preventDefault();
          if (app.focus.isOpen) app.focus.close();
          else app.editor.stop();
        }
        return;
      }
      if (app.focus.isOpen) {
        if (e.key === "Escape") app.focus.close();
        return;
      }
      const t = e.target;
      if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      if (e.code === "Space") {
        e.preventDefault();
        if (!app.spaceDown) {
          app.spaceDown = true;
          app.root.classList.add("tf-space");
        }
        return;
      }
      const k = e.key.toLowerCase();
      if (mod && k === "z" && !e.shiftKey) {
        e.preventDefault();
        app.undo();
        return;
      }
      if (mod && (k === "y" || k === "z" && e.shiftKey)) {
        e.preventDefault();
        app.redo();
        return;
      }
      if (mod && k === "d") {
        e.preventDefault();
        app.duplicateSelection();
        return;
      }
      if (mod && k === "a") {
        e.preventDefault();
        app.select(app.board.nodes.map((n) => n.id));
        return;
      }
      if (mod) return;
      if (e.shiftKey && e.code === "Digit1") {
        e.preventDefault();
        app.fit();
        return;
      }
      switch (e.key) {
        case "Delete":
        case "Backspace":
          e.preventDefault();
          app.deleteSelection();
          break;
        case "Escape":
          if (!app.selection.empty) app.clearSelection();
          else app.goUp();
          break;
        case "Enter":
          if (app.selection.size === 1) {
            e.preventDefault();
            const n = app.node(app.selection.first());
            if (n && n.type === "card") app.editor.start(n.id);
            else if (n) app.editSectionTitle(n.id);
          }
          break;
        case "+":
        case "=":
          app.viewport.zoomCenter(1.25);
          break;
        case "-":
        case "_":
          app.viewport.zoomCenter(0.8);
          break;
        case "0":
          app.viewport.zoomCenter(1 / app.viewport.view.z);
          break;
      }
    });
    window.addEventListener("keyup", (e) => {
      if (e.code === "Space") {
        app.spaceDown = false;
        app.root.classList.remove("tf-space");
      }
    });
    window.addEventListener("blur", () => {
      app.spaceDown = false;
      app.root.classList.remove("tf-space");
    });
  }

  // src/ui/images.js
  function readImage(file, maxSize = 1600) {
    return new Promise((resolve, reject) => {
      const fr = new FileReader();
      fr.onerror = reject;
      fr.onload = () => {
        if (file.type === "image/gif" || file.type === "image/svg+xml") return resolve(fr.result);
        const img = new Image();
        img.onerror = () => resolve(fr.result);
        img.onload = () => {
          const s = Math.min(1, maxSize / Math.max(img.width, img.height));
          if (s === 1 && file.size < 4e5) return resolve(fr.result);
          const c = document.createElement("canvas");
          c.width = Math.round(img.width * s);
          c.height = Math.round(img.height * s);
          c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
          resolve(file.type === "image/png" ? c.toDataURL("image/png") : c.toDataURL("image/jpeg", 0.85));
        };
        img.src = fr.result;
      };
      fr.readAsDataURL(file);
    });
  }

  // src/canvas/viewport.js
  var Viewport = class {
    /**
     * @param {import('../app.js').App} app
     * @param {HTMLElement} el   zone visible (reçoit les événements)
     * @param {HTMLElement} world  calque transformé
     */
    constructor(app, el2, world) {
      this.app = app;
      this.el = el2;
      this.world = world;
      this._animT = null;
    }
    /** Vue du whiteboard courant (mémorisée par whiteboard). */
    get view() {
      const views = this.app.store.views, id = this.app.boardId;
      if (!views[id]) views[id] = { x: 0, y: 0, z: 1, fresh: true };
      return views[id];
    }
    get rect() {
      return this.el.getBoundingClientRect();
    }
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
      return {
        left: r.left + v.x + wr.x * v.z,
        top: r.top + v.y + wr.y * v.z,
        width: wr.w * v.z,
        height: wr.h * v.z,
        get right() {
          return this.left + this.width;
        },
        get bottom() {
          return this.top + this.height;
        }
      };
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
      if (!b) {
        v.x = r.width / 2 - 140;
        v.y = r.height / 2 - 60;
        v.z = 1;
      } else {
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
      this.world.classList.add("tf-anim");
      clearTimeout(this._animT);
      this._animT = setTimeout(() => this.world.classList.remove("tf-anim"), 300);
    }
  };

  // src/blocks/types.js
  var BLOCK_TYPES = {
    text: { label: "Texte", icon: "Aa", keys: "texte text paragraphe normal p", ph: "Tape \xAB / \xBB pour un bloc, \xAB @ \xBB pour lier une carte" },
    h1: { label: "Titre 1", icon: "H1", keys: "titre heading h1 header", ph: "Titre 1" },
    h2: { label: "Titre 2", icon: "H2", keys: "titre heading h2 header sous-titre", ph: "Titre 2" },
    h3: { label: "Titre 3", icon: "H3", keys: "titre heading h3 header", ph: "Titre 3" },
    todo: { label: "Liste \xE0 cocher", icon: "\u2611", keys: "todo t\xE2che tache checkbox case cocher", ph: "\xC0 faire", list: true },
    bullet: { label: "Liste \xE0 puces", icon: "\u2022", keys: "puce bullet liste ul", ph: "\xC9l\xE9ment", list: true },
    number: { label: "Liste num\xE9rot\xE9e", icon: "1.", keys: "num\xE9ro numero number liste ol", ph: "\xC9l\xE9ment", list: true },
    toggle: { label: "Liste d\xE9pliable", icon: "\u25B8", keys: "toggle d\xE9pliable depliable replier", ph: "Titre d\xE9pliable", list: true },
    quote: { label: "Citation", icon: "\u275D", keys: "citation quote", ph: "Citation" },
    callout: { label: "Encadr\xE9", icon: "\u{1F4A1}", keys: "encadr\xE9 encadre callout note info", ph: "Note" },
    code: { label: "Bloc de code", icon: "&lt;/&gt;", keys: "code pre programme", ph: "Code" },
    divider: { label: "S\xE9parateur", icon: "\u2014", keys: "s\xE9parateur separateur divider ligne hr", isVoid: true },
    image: { label: "Image", icon: "\u25A3", keys: "image photo img picture", isVoid: true }
  };
  var TEXT_TYPES = ["text", "h1", "h2", "h3", "todo", "bullet", "number", "toggle", "quote", "callout", "code"];
  var SLASH_ITEMS = ["text", "h1", "h2", "h3", "todo", "bullet", "number", "toggle", "quote", "callout", "code", "divider", "image"].map((t) => ({ id: t, label: BLOCK_TYPES[t].label, icon: BLOCK_TYPES[t].icon, keys: BLOCK_TYPES[t].keys })).concat([
    { id: "mention", label: "Lien vers une carte", icon: "@", keys: "lien link carte mention @" },
    { id: "date", label: "Date du jour", icon: "\u{1F4C5}", keys: "date jour today aujourd" },
    { id: "now", label: "Date et heure", icon: "\u{1F552}", keys: "heure now maintenant time" }
  ]);
  var MD_PREFIX = {
    "#": "h1",
    "##": "h2",
    "###": "h3",
    "-": "bullet",
    "*": "bullet",
    "1.": "number",
    "[]": "todo",
    "[ ]": "todo",
    ">": "quote",
    ">>": "toggle"
  };
  var isVoid = (type) => !!(BLOCK_TYPES[type] && BLOCK_TYPES[type].isVoid);
  var isList = (type) => !!(BLOCK_TYPES[type] && BLOCK_TYPES[type].list);

  // src/blocks/render.js
  function blockHTML(b, num, ctx) {
    const d = BLOCK_TYPES[b.type] || BLOCK_TYPES.text;
    const cls = "tf-block tf-b-" + b.type + (b.checked ? " tf-checked" : "") + (b.open ? " tf-open-t" : "");
    let h = `<div class="${cls}" data-bid="${b.id}"${b.indent ? ` style="--ind:${b.indent}"` : ""}><span class="tf-grip" title="Glisser pour d\xE9placer \xB7 cliquer pour les options"></span>`;
    const bc = () => `<div class="tf-bc" data-ph="${esc(d.ph || "")}">${b.html || ""}</div>`;
    switch (b.type) {
      case "bullet":
        h += '<span class="tf-mark tf-bullet"></span>' + bc();
        break;
      case "number":
        h += `<span class="tf-mark tf-num">${num}.</span>` + bc();
        break;
      case "todo":
        h += '<span class="tf-mark tf-check" title="Cocher"></span>' + bc();
        break;
      case "toggle":
        h += '<span class="tf-mark tf-tog" title="D\xE9plier / replier"></span><div class="tf-tog-wrap">' + bc() + `<div class="tf-bc tf-tog-body" data-ph="Contenu cach\xE9">${b.body || ""}</div></div>`;
        break;
      case "callout":
        h += '<span class="tf-mark tf-callout-ic">\u{1F4A1}</span>' + bc();
        break;
      case "code":
        h += `<pre class="tf-bc tf-code" data-ph="Code">${esc(b.text || "")}</pre>`;
        break;
      case "divider":
        h += "<hr>";
        break;
      case "image": {
        const src = b.asset ? ctx.assetUrl(b.asset) : b.src || "";
        h += `<img src="${esc(src)}" alt="" draggable="false">`;
        break;
      }
      default:
        h += bc();
    }
    return h + "</div>";
  }
  function blocksHTML(blocks, ctx) {
    const counters = {};
    let html = "";
    for (const b of blocks || []) {
      const k = b.indent || 0;
      let num = 0;
      for (const l of Object.keys(counters)) if (+l > k) delete counters[l];
      if (b.type === "number") {
        counters[k] = (counters[k] || 0) + 1;
        num = counters[k];
      } else delete counters[k];
      html += blockHTML(b, num, ctx);
    }
    return html;
  }
  function refreshMentions(root, ctx) {
    root.querySelectorAll(".tf-mention").forEach((m) => {
      const label = ctx.mentionLabel(m.dataset.card);
      m.textContent = label || "carte supprim\xE9e";
      m.classList.toggle("tf-broken", !label);
      m.contentEditable = "false";
      m.title = label ? "Aller \xE0 la carte \xAB " + label + " \xBB" : "Cette carte n'existe plus";
    });
  }

  // src/blocks/model.js
  function htmlText(html) {
    const d = document.createElement("div");
    d.innerHTML = html || "";
    return d.textContent;
  }
  function blockText(b) {
    return b.type === "code" ? b.text || "" : htmlText(b.html);
  }
  function textLen(html) {
    return htmlText(html).length;
  }
  function convertBlock(b, type) {
    if (b.type === type) return;
    if (type === "code") {
      b.text = blockText(b);
      b.html = "";
    } else if (b.type === "code") {
      b.html = esc(b.text || "").replace(/\n/g, "<br>");
      delete b.text;
    }
    if (!BLOCK_TYPES[type].list) delete b.indent;
    if (type !== "todo") delete b.checked;
    if (type === "toggle" && b.body == null) {
      b.body = "";
      b.open = true;
    }
    b.type = type;
  }
  function cardSummary(card, max = 60) {
    if (card.title) return card.title;
    for (const b of card.blocks || []) {
      const t = blockText(b).trim();
      if (t) return t.length > max ? t.slice(0, max - 1) + "\u2026" : t;
      if (b.type === "image") return "Image";
    }
    return "Carte sans titre";
  }

  // src/canvas/nodes.js
  function createCardEl(n) {
    const e = el("div", "tf-node tf-card");
    e.innerHTML = `<div class="tf-card-tools"><button class="tf-ct tf-ct-title" data-card-act="title" title="Ajouter un titre">${I.title}</button><button class="tf-ct tf-ct-collapse" data-card-act="collapse" title="Replier / d\xE9plier">${I.chevron}</button><button class="tf-ct" data-card-act="focus" title="Ouvrir en plein \xE9cran">${I.expand}</button></div><div class="tf-card-head"><span class="tf-board-icon">${I.board}</span><div class="tf-card-title" data-placeholder="Titre de la carte"></div><div class="tf-card-summary"></div></div><div class="tf-card-body"></div><div class="tf-card-foot"></div><div class="tf-handle" data-side="t"></div><div class="tf-handle" data-side="r"></div><div class="tf-handle" data-side="b"></div><div class="tf-handle" data-side="l"></div><div class="tf-resize tf-resize-w"></div>`;
    e._type = "card";
    e.dataset.id = n.id;
    return e;
  }
  function renderCardContent(e, n, ctx, force) {
    e.dataset.color = n.color || "default";
    e.style.setProperty("--c", COLORS[n.color] || "var(--tf-neutral)");
    e.classList.toggle("tf-is-board", !!n.childBoardId);
    e.classList.toggle("tf-no-title", !n.title);
    const t = e.querySelector(".tf-card-title");
    if (t.textContent !== (n.title || "")) t.textContent = n.title || "";
    const body = e.querySelector(".tf-card-body");
    const key = JSON.stringify(n.blocks || []);
    if (force || e._bkey !== key) {
      body.innerHTML = blocksHTML(n.blocks, ctx);
      e._bkey = key;
    }
    refreshMentions(body, ctx);
    e.classList.toggle("tf-empty-body", !(n.blocks && n.blocks.length));
    e.querySelector(".tf-card-summary").textContent = n.title ? "" : cardSummary(n);
    const foot = e.querySelector(".tf-card-foot");
    let html = "";
    if (n.childBoardId) {
      const count = ctx.childCount(n.childBoardId);
      html = `<button class="tf-open" title="Ouvrir le sous-whiteboard">${I.open}<span>Ouvrir</span></button><span class="tf-count">${count} carte${count > 1 ? "s" : ""}</span>`;
    }
    if (foot._html !== html) {
      foot.innerHTML = html;
      foot._html = html;
    }
  }
  var NodeLayer = class {
    constructor(app, sectionsLayer, cardsLayer) {
      this.app = app;
      this.sectionsLayer = sectionsLayer;
      this.cardsLayer = cardsLayer;
      this.els = /* @__PURE__ */ new Map();
    }
    get(id) {
      return this.els.get(id);
    }
    clear() {
      this.sectionsLayer.innerHTML = "";
      this.cardsLayer.innerHTML = "";
      this.els.clear();
    }
    /** Aligne le DOM sur le whiteboard courant. */
    sync() {
      const board = this.app.board, seen = /* @__PURE__ */ new Set();
      for (const n of board.nodes) {
        seen.add(n.id);
        let e = this.els.get(n.id);
        if (!e || e._type !== n.type) {
          if (e) e.remove();
          e = n.type === "section" ? this._createSection(n) : createCardEl(n);
          this.els.set(n.id, e);
        }
        this.update(n, e);
      }
      for (const [id, e] of this.els) if (!seen.has(id)) {
        e.remove();
        this.els.delete(id);
      }
      for (const n of board.nodes) {
        const e = this.els.get(n.id), layer = n.type === "section" ? this.sectionsLayer : this.cardsLayer;
        if (e.parentNode !== layer || e !== layer.lastElementChild) layer.appendChild(e);
      }
    }
    update(n, e = this.els.get(n.id)) {
      if (!e) return;
      this.setGeometry(n, e);
      e.classList.toggle("tf-selected", this.app.selection.has(n.id));
      if (n.type === "section") {
        e.dataset.color = n.color || "default";
        e.style.setProperty("--c", COLORS[n.color] || "var(--tf-neutral)");
        const lab = e.querySelector(".tf-section-label");
        if (document.activeElement !== lab && lab.textContent !== n.title) lab.textContent = n.title;
        return;
      }
      const editing = this.app.editor.isEditingIn(e);
      e.classList.toggle("tf-collapsed", !!n.collapsed && !editing);
      if (!editing) renderCardContent(e, n, this.app.renderCtx);
    }
    /** Force le rendu des blocs d'une carte (après un changement de structure). */
    refresh(n) {
      const e = this.els.get(n.id);
      if (e) {
        renderCardContent(e, n, this.app.renderCtx, true);
        this.update(n, e);
      }
    }
    setGeometry(n, e = this.els.get(n.id)) {
      if (!e) return;
      e.style.left = n.x + "px";
      e.style.top = n.y + "px";
      e.style.width = n.w + "px";
      if (n.type === "section") e.style.height = n.h + "px";
    }
    bringToFront(n) {
      const e = this.els.get(n.id);
      if (e && e.parentNode) e.parentNode.appendChild(e);
    }
    /** Rectangle monde d'un nœud (hauteur réelle pour les cartes). */
    rect(n) {
      if (n.type === "section") return { x: n.x, y: n.y, w: n.w, h: n.h };
      const e = this.els.get(n.id);
      return { x: n.x, y: n.y, w: n.w, h: e ? e.offsetHeight : 80 };
    }
    _createSection(n) {
      const e = el("div", "tf-node tf-section");
      e.innerHTML = '<div class="tf-section-title"><span class="tf-section-label"></span></div><div class="tf-resize tf-resize-both"></div>';
      e._type = "section";
      e.dataset.id = n.id;
      return e;
    }
  };

  // src/core/geometry.js
  function side(r, tx, ty) {
    const cx = r.x + r.w / 2, cy = r.y + r.h / 2;
    const dx = (tx - cx) / (r.w / 2), dy = (ty - cy) / (r.h / 2);
    if (Math.abs(dx) > Math.abs(dy)) return dx > 0 ? "r" : "l";
    return dy > 0 ? "b" : "t";
  }
  function anchor(r, s) {
    switch (s) {
      case "r":
        return [r.x + r.w, r.y + r.h / 2, 1, 0];
      case "l":
        return [r.x, r.y + r.h / 2, -1, 0];
      case "t":
        return [r.x + r.w / 2, r.y, 0, -1];
      default:
        return [r.x + r.w / 2, r.y + r.h, 0, 1];
    }
  }
  function bezier(A, B) {
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
  function edgePath(ra, rb) {
    const A = anchor(ra, side(ra, rb.x + rb.w / 2, rb.y + rb.h / 2));
    const B = anchor(rb, side(rb, ra.x + ra.w / 2, ra.y + ra.h / 2));
    return bezier(A, B);
  }
  function intersects(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  }
  function containsCenter(outer, r) {
    const cx = r.x + r.w / 2, cy = r.y + r.h / 2;
    return cx > outer.x && cx < outer.x + outer.w && cy > outer.y && cy < outer.y + outer.h;
  }
  function containsRect(outer, r) {
    return r.x >= outer.x && r.y >= outer.y && r.x + r.w <= outer.x + outer.w && r.y + r.h <= outer.y + outer.h;
  }
  function wheelZoomFactor(dy) {
    const step = Math.min(Math.abs(dy) * 0.01, 0.1);
    return Math.exp(dy > 0 ? -step : step);
  }

  // src/canvas/edges.js
  var EdgeLayer = class {
    constructor(app, svg, idPrefix) {
      this.app = app;
      this.svg = svg;
      this.prefix = idPrefix;
      this.items = /* @__PURE__ */ new Map();
      const defs = svgEl("defs");
      const marker = (key, fillAttrs) => {
        const m = svgEl("marker", {
          id: this.markerId(key),
          viewBox: "0 0 10 10",
          refX: "8.5",
          refY: "5",
          markerWidth: "7",
          markerHeight: "7",
          orient: "auto-start-reverse",
          markerUnits: "strokeWidth"
        });
        m.appendChild(svgEl("path", Object.assign({ d: "M1,1 L9,5 L1,9 Z" }, fillAttrs)));
        defs.appendChild(m);
      };
      for (const c of Object.keys(COLORS)) marker(c, { fill: COLORS[c] || EDGE_DEFAULT_COLOR });
      marker("sel", { class: "tf-sel-fill" });
      svg.appendChild(defs);
      this.g = svgEl("g");
      svg.appendChild(this.g);
      this.temp = svgEl("path", { class: "tf-temp-edge", "marker-end": `url(#${this.markerId("sel")})` });
      svg.appendChild(this.temp);
    }
    markerId(key) {
      return this.prefix + "-arrow-" + key;
    }
    clear() {
      this.g.innerHTML = "";
      this.items.clear();
    }
    /** Aligne toutes les flèches sur le whiteboard courant. */
    sync() {
      const seen = /* @__PURE__ */ new Set();
      for (const ed of this.app.board.edges) {
        seen.add(ed.id);
        let it = this.items.get(ed.id);
        if (!it) {
          it = this._create(ed);
          this.items.set(ed.id, it);
        }
        this._style(it, ed);
        this._geometry(it, ed);
      }
      for (const [id, it] of this.items) if (!seen.has(id)) {
        it.g.remove();
        this.items.delete(id);
      }
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
      const g = svgEl("g", { class: "tf-edge", "data-id": ed.id });
      const hit = svgEl("path", { class: "tf-edge-hit" });
      const line = svgEl("path", { class: "tf-edge-line" });
      g.appendChild(hit);
      g.appendChild(line);
      this.g.appendChild(g);
      return { g, hit, line, fo: null, sig: "" };
    }
    _style(it, ed) {
      const selected = this.app.selection.edge === ed.id;
      const sig = [ed.color, ed.dir, ed.style, ed.label, selected].join("|");
      if (it.sig === sig) return;
      it.sig = sig;
      const col = COLORS[ed.color] || EDGE_DEFAULT_COLOR;
      const mk = `url(#${this.markerId(selected ? "sel" : COLORS[ed.color] ? ed.color : "default")})`;
      const dir = ed.dir || "end";
      it.g.classList.toggle("tf-selected", selected);
      it.line.setAttribute("stroke", selected ? "" : col);
      it.line.classList.toggle("tf-dashed", ed.style === "dashed");
      if (dir === "end" || dir === "both") it.line.setAttribute("marker-end", mk);
      else it.line.removeAttribute("marker-end");
      if (dir === "start" || dir === "both") it.line.setAttribute("marker-start", mk);
      else it.line.removeAttribute("marker-start");
      if (it.fo) {
        it.fo.remove();
        it.fo = null;
      }
      if (ed.label) {
        it.fo = svgEl("foreignObject", { width: 200, height: 28, class: "tf-edge-label-fo" });
        const lab = el("div", "tf-edge-label", `<span>${esc(ed.label)}</span>`);
        lab.style.setProperty("--c", col);
        it.fo.appendChild(lab);
        it.g.appendChild(it.fo);
      }
    }
    _geometry(it, ed) {
      const a = this.app.node(ed.from), b = this.app.node(ed.to);
      if (!a || !b) {
        it.g.style.display = "none";
        return;
      }
      it.g.style.display = "";
      const p = edgePath(this.app.nodes.rect(a), this.app.nodes.rect(b));
      it.hit.setAttribute("d", p.d);
      it.line.setAttribute("d", p.d);
      if (it.fo) {
        it.fo.setAttribute("x", p.mid[0] - 100);
        it.fo.setAttribute("y", p.mid[1] - 14);
      }
    }
  };

  // src/canvas/minimap.js
  var Minimap = class {
    constructor(app, wrap) {
      this.app = app;
      this.wrap = wrap;
      this.canvas = document.createElement("canvas");
      wrap.appendChild(this.canvas);
      this._raf = null;
      this._map = null;
      this.canvas.addEventListener("pointerdown", (e) => this._onDown(e));
    }
    get visible() {
      return !this.wrap.hidden;
    }
    setVisible(v) {
      this.wrap.hidden = !v;
      if (v) this.schedule();
    }
    schedule() {
      if (!this.visible || this._raf) return;
      this._raf = requestAnimationFrame(() => {
        this._raf = null;
        this.draw();
      });
    }
    draw() {
      const app = this.app, cv = this.canvas;
      const W = this.wrap.clientWidth, H = this.wrap.clientHeight;
      if (!W || !H) return;
      const dpr = window.devicePixelRatio || 1;
      if (cv.width !== W * dpr || cv.height !== H * dpr) {
        cv.width = W * dpr;
        cv.height = H * dpr;
      }
      const ctx = cv.getContext("2d");
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      const vw = app.viewport.visibleWorld();
      const b = app.bounds() || vw;
      const x1 = Math.min(b.x, vw.x), y1 = Math.min(b.y, vw.y);
      const x2 = Math.max(b.x + b.w, vw.x + vw.w), y2 = Math.max(b.y + b.h, vw.y + vw.h);
      const pad = 8;
      const s = Math.min((W - pad * 2) / (x2 - x1), (H - pad * 2) / (y2 - y1));
      const ox = pad + (W - pad * 2 - (x2 - x1) * s) / 2 - x1 * s;
      const oy = pad + (H - pad * 2 - (y2 - y1) * s) / 2 - y1 * s;
      this._map = { s, ox, oy };
      const css = getComputedStyle(app.root);
      const neutral = css.getPropertyValue("--tf-mm-card").trim() || "#5d616b";
      const accent = css.getPropertyValue("--tf-accent").trim() || "#5b8cff";
      for (const n of app.board.nodes) {
        const r = app.nodes.rect(n), c = COLORS[n.color];
        const x = ox + r.x * s, y = oy + r.y * s, w = Math.max(2, r.w * s), h = Math.max(2, r.h * s);
        if (n.type === "section") {
          ctx.globalAlpha = 0.14;
          ctx.fillStyle = c || neutral;
          ctx.fillRect(x, y, w, h);
          ctx.globalAlpha = 0.8;
          ctx.strokeStyle = c || neutral;
          ctx.lineWidth = 1;
          ctx.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);
        } else {
          ctx.globalAlpha = 0.95;
          ctx.fillStyle = c || neutral;
          roundRect(ctx, x, y, w, h, Math.min(2, w / 4));
          ctx.fill();
        }
      }
      ctx.globalAlpha = 1;
      ctx.strokeStyle = accent;
      ctx.lineWidth = 1.5;
      ctx.fillStyle = "rgba(120,150,255,0.10)";
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
      this.app.drag = { move: go, up() {
      } };
    }
  };
  function roundRect(c, x, y, w, h, r) {
    c.beginPath();
    c.moveTo(x + r, y);
    c.arcTo(x + w, y, x + w, y + h, r);
    c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r);
    c.arcTo(x, y, x + w, y, r);
    c.closePath();
  }

  // src/blocks/drag.js
  function startBlockDrag(app, e, { cardId, bid, grip, scope }) {
    const src = app.node(cardId);
    if (!src) return;
    const bsel = app.editor.bsel;
    const group = app.editor.cardId === cardId && bsel.has(bid) ? bsel.ids.slice() : [bid];
    const blockEls = group.map((id) => grip.closest(".tf-card").querySelector(`.tf-block[data-bid="${id}"]`)).filter(Boolean);
    const sx = e.clientX, sy = e.clientY, rr = app.root.getBoundingClientRect();
    const onCanvas = scope === app.viewport.el;
    let moved = false, ghost = null, line = null, drop = null;
    const findDrop = (ev) => {
      let card = null;
      for (const x of document.elementsFromPoint(ev.clientX, ev.clientY)) {
        if (x.closest(".tf-ui")) continue;
        const c = x.closest(".tf-card");
        if (c && scope.contains(c)) {
          card = c;
          break;
        }
      }
      if (!card) return onCanvas ? { canvas: true } : null;
      const blocks = Array.from(card.querySelectorAll(".tf-card-body > .tf-block"));
      let idx = blocks.length;
      for (let j = 0; j < blocks.length; j++) {
        const r = blocks[j].getBoundingClientRect();
        if (ev.clientY < r.top + r.height / 2) {
          idx = j;
          break;
        }
      }
      let top;
      if (!blocks.length) {
        const hd = card.querySelector(".tf-card-head");
        top = hd.offsetTop + hd.offsetHeight + 4;
      } else if (idx < blocks.length) top = blocks[idx].offsetTop;
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
          blockEls.forEach((x) => x.classList.add("tf-dragging"));
          app.root.classList.add("tf-block-dragging");
          ghost = el(
            "div",
            "tf-ui tf-block-ghost",
            `<span class="tf-menu-ic">${BLOCK_TYPES[b.type].icon}</span><span class="tf-ghost-t"></span>`
          );
          ghost.querySelector(".tf-ghost-t").textContent = group.length > 1 ? group.length + " blocs" : blockText(b).trim().slice(0, 60) || BLOCK_TYPES[b.type].label;
          app.root.appendChild(ghost);
          line = el("div", "tf-drop-line");
        }
        ghost.style.left = ev.clientX - rr.left + 14 + "px";
        ghost.style.top = ev.clientY - rr.top + 12 + "px";
        drop = findDrop(ev);
        ghost.classList.toggle("tf-ghost-new", !!(drop && drop.canvas));
        if (drop && drop.card) {
          if (line.parentNode !== drop.card) drop.card.appendChild(line);
          line.style.top = drop.top + "px";
          line.style.display = "block";
        } else line.style.display = "none";
      },
      up(ev) {
        app.root.classList.remove("tf-block-dragging");
        if (!moved) {
          app.editor.openBlockMenu(cardId, bid, grip.getBoundingClientRect());
          return;
        }
        blockEls.forEach((x) => x.classList.remove("tf-dragging"));
        ghost.remove();
        line.remove();
        const s = app.node(cardId);
        if (!s || !drop) return;
        const idx = group.map((id) => s.blocks.findIndex((x) => x.id === id)).filter((i) => i >= 0).sort((a, b) => a - b);
        if (!idx.length) return;
        const moving = idx.map((i) => s.blocks[i]);
        const takeOut = () => {
          s.blocks = s.blocks.filter((x) => !moving.includes(x));
        };
        let tgt;
        if (drop.card) {
          tgt = app.node(drop.card.dataset.id);
          if (!tgt) return;
          let ti = drop.idx;
          if (tgt === s) {
            if (ti >= idx[0] && ti <= idx[idx.length - 1] + 1) return;
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
          const img = moving.length === 1 && moving[0].type === "image";
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

  // src/canvas/interactions.js
  var SPECIALS = ".tf-grip,.tf-check,.tf-tog,.tf-mention,[data-card-act]";
  var DBL_DELAY = 450;
  var Interactions = class {
    /** @param {import('../app.js').App} app */
    constructor(app) {
      this.app = app;
      this.lastDown = null;
      const vp = app.viewport.el;
      vp.addEventListener("pointerdown", (e) => this.onDown(e));
      vp.addEventListener("wheel", (e) => this.onWheel(e), { passive: false });
      vp.addEventListener("dblclick", (e) => this.onDblClick(e));
      vp.addEventListener("click", (e) => this.onClick(e));
      vp.addEventListener("dragover", (e) => {
        if (e.dataTransfer && Array.from(e.dataTransfer.types).includes("Files")) e.preventDefault();
      });
      vp.addEventListener("drop", (e) => this.onDrop(e));
      window.addEventListener("pointermove", (e) => {
        if (app.drag) app.drag.move(e);
      });
      const end = (e) => {
        if (app.drag) {
          const d = app.drag;
          app.drag = null;
          d.up(e);
        }
      };
      window.addEventListener("pointerup", end);
      window.addEventListener("pointercancel", end);
    }
    /** Clic sur un élément actif d'une carte (poignée, case, lien, outils). */
    handleSpecial(e, sp, scope) {
      const app = this.app;
      const cardEl = sp.closest(".tf-card");
      const cardId = cardEl && cardEl.dataset.id;
      if (sp.classList.contains("tf-mention")) {
        if (app.focus.isOpen) app.focus.close();
        else if (app.editor.active) app.editor.stop();
        app.revealCard(sp.dataset.card);
        return;
      }
      if (sp.dataset.cardAct) {
        const act = sp.dataset.cardAct;
        if (act === "title") return app.editor.showTitle();
        if (act === "focus") return app.focus.open(cardId);
        if (act === "collapse") return app.toggleCollapse(cardId);
        return;
      }
      if (sp.classList.contains("tf-grip")) {
        const be = sp.closest(".tf-block");
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
        if (inside && !special && !t.closest(".tf-handle,.tf-resize,.tf-open")) return;
        const keepEditing = inside && special && !special.classList.contains("tf-mention") && special.dataset.cardAct !== "collapse";
        if (!keepEditing) app.editor.stop();
      }
      if (special && special.isConnected) {
        e.preventDefault();
        return this.handleSpecial(e, special, app.viewport.el);
      }
      const editable = t.closest('[contenteditable="true"],[contenteditable="plaintext-only"]');
      if (document.activeElement && document.activeElement.isContentEditable && !editable) document.activeElement.blur();
      if (!editable) app.root.focus({ preventScroll: true });
      if (e.button === 1 || e.button === 0 && app.spaceDown) {
        e.preventDefault();
        return this.startPan(e);
      }
      if (e.button !== 0 || editable) return;
      if (t.closest(".tf-open")) return;
      const h = t.closest(".tf-handle");
      if (h) {
        e.preventDefault();
        return this.startConnect(e, h.closest("[data-id]").dataset.id, h.dataset.side);
      }
      const rs = t.closest(".tf-resize");
      if (rs) {
        e.preventDefault();
        return this.startResize(e, rs.closest("[data-id]").dataset.id);
      }
      const ne = t.closest(".tf-card, .tf-section-title");
      if (ne) {
        e.preventDefault();
        const id = ne.closest("[data-id]").dataset.id;
        const now = Date.now(), lc = this.lastDown;
        this.lastDown = { t: now, x: e.clientX, y: e.clientY, id };
        if (lc && lc.id === id && now - lc.t < DBL_DELAY && Math.hypot(e.clientX - lc.x, e.clientY - lc.y) < 8 && !e.shiftKey) {
          this.lastDown = null;
          if (ne.classList.contains("tf-card")) return app.editor.start(id, { evt: e });
          return app.editSectionTitle(id);
        }
        return this.startMove(e, id);
      }
      const ee = t.closest(".tf-edge");
      if (ee) {
        e.preventDefault();
        app.selectEdge(ee.dataset.id);
        return;
      }
      e.preventDefault();
      if (app.mode === "mouse" && !e.shiftKey) {
        app.clearSelection();
        this.startPan(e);
      } else this.startMarquee(e);
    }
    startPan(e) {
      const app = this.app, v = app.viewport.view;
      const sx = e.clientX, sy = e.clientY, x0 = v.x, y0 = v.y;
      app.root.classList.add("tf-panning");
      app.drag = {
        move(ev) {
          v.x = x0 + ev.clientX - sx;
          v.y = y0 + ev.clientY - sy;
          app.viewport.apply();
        },
        up() {
          app.root.classList.remove("tf-panning");
        }
      };
    }
    startMarquee(e) {
      const app = this.app, vr = app.viewport.rect;
      const p0 = app.viewport.toWorld(e.clientX, e.clientY), sx = e.clientX, sy = e.clientY;
      const base = e.shiftKey ? new Set(app.selection.ids) : /* @__PURE__ */ new Set();
      if (!e.shiftKey) {
        app.selection.clear();
        app.render();
      }
      const m = app.marquee;
      app.drag = {
        move(ev) {
          m.style.cssText = `display:block;left:${Math.min(sx, ev.clientX) - vr.left}px;top:${Math.min(sy, ev.clientY) - vr.top}px;width:${Math.abs(ev.clientX - sx)}px;height:${Math.abs(ev.clientY - sy)}px`;
          const p1 = app.viewport.toWorld(ev.clientX, ev.clientY);
          const r = { x: Math.min(p0.x, p1.x), y: Math.min(p0.y, p1.y), w: Math.abs(p1.x - p0.x), h: Math.abs(p1.y - p0.y) };
          const ids = new Set(base);
          for (const n of app.board.nodes) {
            const nr = app.nodes.rect(n);
            if (n.type === "section" ? containsRect(r, nr) : intersects(nr, r)) ids.add(n.id);
          }
          app.selection.set(ids);
          for (const [id, el2] of app.nodes.els) el2.classList.toggle("tf-selected", ids.has(id));
        },
        up() {
          m.style.display = "none";
          app.render();
        }
      };
    }
    startMove(e, id) {
      const app = this.app, n = app.node(id);
      if (!n) return;
      if (e.shiftKey) {
        app.selection.toggle(id);
        app.render();
        return;
      }
      if (!app.selection.has(id)) app.selection.set([id]);
      app.selection.edge = null;
      if (n.type === "card") app.bringToFront(n);
      app.render();
      const moving = new Set(app.selection.ids);
      for (const s of app.board.nodes) {
        if (s.type !== "section" || !app.selection.has(s.id)) continue;
        const sr = app.nodes.rect(s);
        for (const o of app.board.nodes) if (o.id !== s.id && containsCenter(sr, app.nodes.rect(o))) moving.add(o.id);
      }
      const starts = [];
      for (const mid of moving) {
        const m = app.node(mid);
        if (m) starts.push({ n: m, x: m.x, y: m.y });
      }
      const sx = e.clientX, sy = e.clientY, z = app.viewport.view.z;
      let moved = false;
      app.root.classList.add("tf-moving");
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
          app.root.classList.remove("tf-moving");
          if (moved) app.commit();
        }
      };
    }
    startResize(e, id) {
      const app = this.app, n = app.node(id);
      if (!n) return;
      const sx = e.clientX, sy = e.clientY, w0 = n.w, h0 = n.h, z = app.viewport.view.z;
      const ids = /* @__PURE__ */ new Set([id]);
      app.drag = {
        move(ev) {
          n.w = Math.round(Math.max(n.type === "section" ? 200 : 160, w0 + (ev.clientX - sx) / z));
          if (n.type === "section") n.h = Math.round(Math.max(120, h0 + (ev.clientY - sy) / z));
          app.nodes.setGeometry(n);
          app.edges.updateFor(ids);
          app.minimap.schedule();
          app.toolbars.position();
        },
        up() {
          app.commit();
        }
      };
    }
    /** Nœud sous le pointeur (carte ou titre de section), hors `exclude`. */
    nodeAt(cx, cy, exclude) {
      for (const x of document.elementsFromPoint(cx, cy)) {
        const c = x.closest && x.closest(".tf-card, .tf-section-title");
        if (c && this.app.viewport.el.contains(c)) {
          const id = c.closest("[data-id]").dataset.id;
          if (id !== exclude) return id;
        }
      }
      return null;
    }
    startConnect(e, fromId, fromSide) {
      const app = this.app, from = app.node(fromId), path = app.edges.temp;
      if (!from) return;
      let target = null;
      app.root.classList.add("tf-connecting");
      const setTarget = (id) => {
        if (target === id) return;
        if (target && app.nodes.get(target)) app.nodes.get(target).classList.remove("tf-drop-target");
        target = id;
        if (target && app.nodes.get(target)) app.nodes.get(target).classList.add("tf-drop-target");
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
        path.setAttribute("d", bezier(A, B).d);
        path.style.display = "block";
      };
      draw(e);
      app.drag = {
        move: draw,
        up: (ev) => {
          path.style.display = "none";
          app.root.classList.remove("tf-connecting");
          const to = target;
          setTarget(null);
          const b = app.board;
          if (to) {
            if (!b.edges.some((x2) => x2.from === fromId && x2.to === to)) {
              b.edges.push(newEdge(fromId, to));
              app.render();
              app.commit();
            }
            return;
          }
          const p = app.viewport.toWorld(ev.clientX, ev.clientY), ra = app.nodes.rect(from);
          if (Math.hypot(p.x - (ra.x + ra.w / 2), p.y - (ra.y + ra.h / 2)) < Math.max(ra.w, ra.h) / 2 + 20) return;
          const w = 280, dx = p.x - (ra.x + ra.w / 2), dy = p.y - (ra.y + ra.h / 2);
          let x = p.x - w / 2, y = p.y - 30;
          if (Math.abs(dx) > Math.abs(dy)) x = dx > 0 ? p.x : p.x - w;
          else y = dy > 0 ? p.y : p.y - 80;
          const n = newCard(x, y, { w, color: from.type === "card" ? from.color : "default" });
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
      if (e.ctrlKey || e.metaKey) app.viewport.zoomAt(sx, sy, wheelZoomFactor(dy));
      else if (app.mode === "touchpad") app.viewport.panBy(-dx, -dy);
      else if (e.shiftKey) app.viewport.panBy(-(dy || dx), 0);
      else app.viewport.zoomAt(sx, sy, wheelZoomFactor(dy));
    }
    onDblClick(e) {
      const app = this.app, t = e.target;
      if (t.closest(".tf-open,.tf-handle,.tf-resize," + SPECIALS)) return;
      const card = t.closest(".tf-card");
      if (card) {
        if (app.editor.cardId !== card.dataset.id) app.editor.start(card.dataset.id, { evt: e });
        return;
      }
      if (t.closest(".tf-section-title")) return;
      const ed = t.closest(".tf-edge");
      if (ed) return app.editEdgeLabel(ed.dataset.id);
      const p = app.viewport.toWorld(e.clientX, e.clientY);
      app.addCard(p.x - 140, p.y - 24, null, true);
    }
    onClick(e) {
      const o = e.target.closest(".tf-open");
      if (!o) return;
      const n = this.app.node(o.closest("[data-id]").dataset.id);
      if (n && n.childBoardId) this.app.openBoard(n.childBoardId);
    }
    onDrop(e) {
      const app = this.app, files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      e.preventDefault();
      const cardEl = e.target.closest && e.target.closest(".tf-card");
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
            app.addCard(p.x - 160 + off, p.y - 40 + off, { w: 320, blocks: [nb("image", "", { asset })] }, false);
          }
        });
      }
    }
  };

  // src/blocks/caret.js
  function caretOffset(el2) {
    const s = window.getSelection();
    if (!s.rangeCount) return 0;
    const r = s.getRangeAt(0);
    if (!el2.contains(r.startContainer)) return 0;
    const pre = document.createRange();
    pre.selectNodeContents(el2);
    pre.setEnd(r.startContainer, r.startOffset);
    return pre.toString().length;
  }
  function rangeAt(el2, pos) {
    const r = document.createRange();
    const w = document.createTreeWalker(el2, NodeFilter.SHOW_TEXT);
    let t, rem = pos;
    while (t = w.nextNode()) {
      if (rem <= t.length) {
        r.setStart(t, rem);
        r.collapse(true);
        return r;
      }
      rem -= t.length;
    }
    r.selectNodeContents(el2);
    r.collapse(false);
    return r;
  }
  function setCaret(el2, pos) {
    el2.focus({ preventScroll: true });
    let r;
    if (pos === "start" || pos === 0) {
      r = document.createRange();
      r.setStart(el2, 0);
      r.collapse(true);
    } else if (pos === "end" || pos == null) {
      r = document.createRange();
      r.selectNodeContents(el2);
      r.collapse(false);
    } else r = rangeAt(el2, pos);
    const s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }
  function deleteTextRange(el2, a, b) {
    const r = rangeAt(el2, a), r2 = rangeAt(el2, b);
    r.setEnd(r2.startContainer, r2.startOffset);
    r.deleteContents();
    const s = window.getSelection();
    s.removeAllRanges();
    r.collapse(true);
    s.addRange(r);
  }
  function extractTail(el2) {
    const r = window.getSelection().getRangeAt(0);
    r.deleteContents();
    const tail = document.createRange();
    tail.setStart(r.startContainer, r.startOffset);
    tail.setEnd(el2, el2.childNodes.length);
    const d = document.createElement("div");
    d.appendChild(tail.extractContents());
    return d.innerHTML;
  }
  function caretRect() {
    const s = window.getSelection();
    if (!s.rangeCount) return null;
    const r = s.getRangeAt(0).cloneRange();
    const rects = r.getClientRects();
    if (rects.length) return rects[0];
    const span = document.createElement("span");
    span.textContent = "\u200B";
    r.insertNode(span);
    const rr = span.getBoundingClientRect();
    const parent = span.parentNode;
    span.remove();
    if (parent) parent.normalize();
    return rr;
  }
  function onEdgeLine(el2, top) {
    const c = caretRect();
    if (!c || !c.height) return true;
    const r = el2.getBoundingClientRect();
    return top ? c.top - r.top < c.height * 0.8 : r.bottom - c.bottom < c.height * 0.8;
  }
  function rangeFromPoint(x, y) {
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

  // src/blocks/clipboard.js
  var TEXT_PREFIX = { h1: "# ", h2: "## ", h3: "### ", bullet: "- ", quote: "> ", toggle: "\u25B8 " };
  var HTML_TAG = { h1: "h1", h2: "h2", h3: "h3", quote: "blockquote" };
  var internal = null;
  function blocksToText(blocks) {
    const out = [];
    let num = 0;
    for (const b of blocks) {
      const pad = "  ".repeat(b.indent || 0);
      num = b.type === "number" ? num + 1 : 0;
      if (b.type === "code") out.push("```\n" + (b.text || "") + "\n```");
      else if (b.type === "divider") out.push("---");
      else if (b.type === "image") out.push("[image]");
      else if (b.type === "todo") out.push(pad + (b.checked ? "[x] " : "[ ] ") + blockText(b));
      else if (b.type === "number") out.push(pad + num + ". " + blockText(b));
      else out.push(pad + (TEXT_PREFIX[b.type] || "") + blockText(b));
    }
    return out.join("\n");
  }
  function blocksToHTML(blocks, assetUrl) {
    let html = "", list = null;
    const close = () => {
      if (list) {
        html += `</${list}>`;
        list = null;
      }
    };
    for (const b of blocks) {
      const want = b.type === "bullet" || b.type === "todo" ? "ul" : b.type === "number" ? "ol" : null;
      if (want !== list) {
        close();
        if (want) {
          html += `<${want}>`;
          list = want;
        }
      }
      const inner = b.html || "";
      if (want) html += `<li>${b.type === "todo" ? b.checked ? "\u2611 " : "\u2610 " : ""}${inner}</li>`;
      else if (b.type === "code") html += `<pre><code>${esc(b.text || "")}</code></pre>`;
      else if (b.type === "divider") html += "<hr>";
      else if (b.type === "image") {
        const src = b.asset ? assetUrl(b.asset) : b.src;
        if (src) html += `<p><img src="${esc(src)}"></p>`;
      } else {
        const tag = HTML_TAG[b.type] || "p";
        html += `<${tag}>${inner}</${tag}>`;
      }
    }
    close();
    return html;
  }
  function writeBlocks(clipboardData, blocks, assetUrl) {
    const text = blocksToText(blocks);
    internal = { text: norm2(text), blocks: clone(blocks) };
    clipboardData.setData("text/plain", text);
    clipboardData.setData("text/html", blocksToHTML(blocks, assetUrl));
  }
  function readBlocks(text) {
    if (!internal || norm2(text) !== internal.text) return null;
    return clone(internal.blocks).map((b) => Object.assign(b, { id: uid("k") }));
  }
  var norm2 = (t) => (t || "").replace(/\r/g, "").trim();

  // src/blocks/blockselect.js
  var BlockSelection = class {
    /** @param {import('./editor.js').CardEditor} editor */
    constructor(editor) {
      this.editor = editor;
      this.app = editor.app;
      this.ids = [];
      this.anchor = null;
      this.head = null;
      const root = this.app.root;
      root.addEventListener("pointerdown", (e) => this._onPointerDown(e));
      root.addEventListener("copy", (e) => this._onCopy(e, false));
      root.addEventListener("cut", (e) => this._onCopy(e, true));
    }
    get active() {
      return this.ids.length > 0;
    }
    has(bid) {
      return this.ids.includes(bid);
    }
    /** Blocs sélectionnés, dans l'ordre de la carte. */
    blocks() {
      const n = this.editor.card;
      return n ? n.blocks.filter((b) => this.ids.includes(b.id)) : [];
    }
    /** Sélectionne les blocs de `from` à `to` (inclus). */
    select(from, to) {
      const n = this.editor.card;
      if (!n) return;
      const i = n.blocks.findIndex((b) => b.id === from), j = n.blocks.findIndex((b) => b.id === to);
      if (i < 0 || j < 0) return;
      if (!this.active) this._leaveText();
      this.anchor = from;
      this.head = to;
      this.ids = n.blocks.slice(Math.min(i, j), Math.max(i, j) + 1).map((b) => b.id);
      this.paint();
    }
    selectAll() {
      const n = this.editor.card;
      if (n && n.blocks.length) this.select(n.blocks[0].id, n.blocks[n.blocks.length - 1].id);
    }
    /** Quitte la sélection de blocs ; `caret` : 'start'|'end' pour remettre le curseur. */
    clear(caret) {
      if (!this.active) return;
      const target = caret === "start" ? this.ids[0] : this.ids[this.ids.length - 1];
      this.ids = [];
      this.anchor = this.head = null;
      this.paint();
      if (caret && this.editor.active) this.editor.focusBlock(target, caret);
    }
    /** Met à jour l'affichage (à rappeler après un nouveau rendu de la carte). */
    paint() {
      const host = this.editor.host;
      this.app.root.classList.toggle("tf-bsel-on", this.active);
      if (!host) return;
      host.querySelectorAll(".tf-card-body > .tf-block").forEach((be) => {
        be.classList.toggle("tf-bsel", this.ids.includes(be.dataset.bid));
      });
    }
    // ------------------------------------------------ souris
    _onPointerDown(e) {
      const ed = this.editor;
      if (!ed.active || e.button !== 0) return;
      const host = ed.host, be = e.target.closest && e.target.closest(".tf-card-body > .tf-block");
      const inHost = be && host.contains(be);
      if (inHost && e.target.closest(".tf-grip") && this.has(be.dataset.bid)) return;
      if (!inHost) {
        if (this.active && !e.target.closest(".tf-ui")) this.clear();
        return;
      }
      if (e.shiftKey) {
        e.preventDefault();
        const cur = this.active ? this.anchor : this._caretBlock();
        if (cur) this.select(cur, be.dataset.bid);
        return;
      }
      if (this.active) this.clear();
      if (e.target.closest(".tf-grip,.tf-check,.tf-tog,.tf-mention")) return;
      this._trackDrag(e, be.dataset.bid);
    }
    /** Pendant un glisser commencé dans un bloc : dès qu'on entre dans un autre bloc, on passe en sélection de blocs. */
    _trackDrag(e, startBid) {
      const host = this.editor.host;
      const move = (ev) => {
        if (!this.editor.active || this.editor.host !== host) return end();
        const bid = this._blockAtY(host, ev.clientY);
        if (!bid) return;
        if (!this.active && bid === startBid) return;
        this.app.root.classList.add("tf-bsel-drag");
        window.getSelection().removeAllRanges();
        this.select(startBid, bid);
      };
      const end = () => {
        window.removeEventListener("pointermove", move);
        window.removeEventListener("pointerup", end);
        window.removeEventListener("pointercancel", end);
        this.app.root.classList.remove("tf-bsel-drag");
      };
      window.addEventListener("pointermove", move);
      window.addEventListener("pointerup", end);
      window.addEventListener("pointercancel", end);
    }
    _blockAtY(host, y) {
      const list = host.querySelectorAll(".tf-card-body > .tf-block");
      if (!list.length) return null;
      for (const be of list) {
        const r = be.getBoundingClientRect();
        if (y < r.bottom + 1) return be.dataset.bid;
      }
      return list[list.length - 1].dataset.bid;
    }
    _caretBlock() {
      const a = document.activeElement;
      const be = a && a.closest && a.closest(".tf-card-body > .tf-block");
      return be && this.editor.host.contains(be) ? be.dataset.bid : null;
    }
    /** On quitte le texte : l'édition continue, mais le clavier va à la sélection de blocs. */
    _leaveText() {
      const a = document.activeElement;
      if (a && a.isContentEditable) {
        const c = this.editor.blockCtx(a);
        if (c) this.editor.sync(c);
        a.blur();
      }
      this.app.menu.close();
      window.getSelection().removeAllRanges();
      this.app.root.focus({ preventScroll: true });
    }
    // ------------------------------------------------ clavier
    /** Entrée clavier pendant l'édition. @returns {boolean} true si la touche a été traitée. */
    onKey(e) {
      const ed = this.editor, mod = e.ctrlKey || e.metaKey, k = e.key;
      if (!this.active) {
        const c = ed.blockCtx(e.target);
        if (!c || c.isBody) return false;
        if (e.shiftKey && !mod && (k === "ArrowUp" || k === "ArrowDown")) {
          const up = k === "ArrowUp", n2 = c.n, nextIdx = c.idx + (up ? -1 : 1);
          if (!ed.atEdge(c.bc, up) || !n2.blocks[nextIdx]) return false;
          e.preventDefault();
          this.select(c.b.id, n2.blocks[nextIdx].id);
          return true;
        }
        if (mod && k.toLowerCase() === "a") {
          const s = window.getSelection();
          const all = !c.bc.textContent.length || s.toString().length >= c.bc.textContent.length;
          if (!all) return false;
          e.preventDefault();
          this.selectAll();
          return true;
        }
        return false;
      }
      e.stopPropagation();
      const n = ed.card;
      if (k === "Escape") {
        e.preventDefault();
        this.clear("end");
        return true;
      }
      if (k === "Delete" || k === "Backspace") {
        e.preventDefault();
        this.remove();
        return true;
      }
      if (mod && k.toLowerCase() === "a") {
        e.preventDefault();
        this.selectAll();
        return true;
      }
      if (k === "ArrowUp" || k === "ArrowDown") {
        e.preventDefault();
        const up = k === "ArrowUp";
        if (!e.shiftKey) {
          this.clear(up ? "start" : "end");
          return true;
        }
        const i = n.blocks.findIndex((b) => b.id === this.head) + (up ? -1 : 1);
        if (n.blocks[i]) this.select(this.anchor, n.blocks[i].id);
        return true;
      }
      if (k === "Tab") {
        e.preventDefault();
        let changed = false;
        for (const b of this.blocks()) {
          if (!isList(b.type)) continue;
          b.indent = clamp((b.indent || 0) + (e.shiftKey ? -1 : 1), 0, 4);
          if (!b.indent) delete b.indent;
          changed = true;
        }
        if (changed) ed.refresh();
        return true;
      }
      if (k === "Enter") {
        e.preventDefault();
        this.clear("end");
        return true;
      }
      if (mod && /^[cxv]$/i.test(k)) return true;
      if (mod || k.length !== 1) return true;
      e.preventDefault();
      if (this.remove(true)) document.execCommand("insertText", false, k);
      return true;
    }
    /**
     * Supprime les blocs sélectionnés et place le curseur à leur place.
     * @param {boolean} [fresh] toujours laisser un bloc texte vide à leur place (pour y saisir ou coller)
     * @returns le bloc où se trouve le curseur
     */
    remove(fresh) {
      const ed = this.editor, n = ed.card;
      if (!n || !this.active) return null;
      const first = n.blocks.findIndex((b) => this.ids.includes(b.id));
      n.blocks = n.blocks.filter((b) => !this.ids.includes(b.id));
      const prev = n.blocks.slice(0, first).reverse().find((b) => !isVoid(b.type));
      const next = n.blocks.slice(first).find((b) => !isVoid(b.type));
      let target = fresh ? null : prev || next, pos = prev ? "end" : "start";
      if (!target) {
        target = nb("text");
        n.blocks.splice(first, 0, target);
        pos = "start";
      }
      this.ids = [];
      this.anchor = this.head = null;
      this.paint();
      ed.refresh();
      ed.focusBlock(target.id, pos);
      this.app.onCardResize(n.id);
      return target;
    }
    _onCopy(e, cut) {
      if (!this.active || !e.clipboardData) return;
      e.preventDefault();
      writeBlocks(e.clipboardData, this.blocks(), (id) => this.app.store.assetUrl(id));
      if (cut) this.remove();
      else this.app.toast(this.ids.length + " bloc" + (this.ids.length > 1 ? "s" : "") + " copi\xE9" + (this.ids.length > 1 ? "s" : ""));
    }
  };

  // src/blocks/editor.js
  var CardEditor = class {
    /** @param {import('../app.js').App} app */
    constructor(app) {
      this.app = app;
      this.cardId = null;
      this.host = null;
      this.trigger = null;
      this.bsel = new BlockSelection(this);
    }
    get active() {
      return !!this.cardId;
    }
    get card() {
      return this.cardId ? this.app.node(this.cardId) : null;
    }
    isEditingIn(el2) {
      return !!this.host && this.host === el2;
    }
    // ------------------------------------------------ cycle de vie
    /**
     * @param {string} cardId
     * @param {{evt?: MouseEvent, host?: HTMLElement, focusTitle?: boolean}} [opts]
     */
    start(cardId, opts = {}) {
      const app = this.app, n = app.node(cardId);
      const host = opts.host || app.nodes.get(cardId);
      if (!n || n.type !== "card" || !host) return;
      if (this.active && (this.cardId !== cardId || this.host !== host)) this.stop();
      this.cardId = cardId;
      this.host = host;
      app.selection.set([cardId]);
      if (host === app.nodes.get(cardId)) app.bringToFront(n);
      if (!n.blocks.length || isVoid(n.blocks[n.blocks.length - 1].type)) n.blocks.push(nb("text"));
      host.classList.add("tf-editing");
      app.refreshCard(cardId);
      app.render();
      const title = host.querySelector(".tf-card-title");
      if (opts.focusTitle) {
        this.showTitle(true);
        return;
      }
      if (opts.evt) {
        const range = rangeFromPoint(opts.evt.clientX, opts.evt.clientY);
        const node = range && (range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentNode);
        const target = node && node.closest && node.closest(".tf-bc, .tf-card-title");
        if (target && host.contains(target)) {
          target.focus({ preventScroll: true });
          const s = window.getSelection();
          s.removeAllRanges();
          s.addRange(range);
          return;
        }
      }
      const bcs = host.querySelectorAll(".tf-block > .tf-bc, .tf-tog-wrap > .tf-bc:first-child");
      setCaret(bcs[bcs.length - 1] || title, "end");
    }
    stop() {
      const app = this.app, id = this.cardId;
      if (!id) return;
      const n = app.node(id), host = this.host;
      this.bsel.clear();
      app.menu.close();
      this.trigger = null;
      this.cardId = null;
      this.host = null;
      if (host) {
        host.classList.remove("tf-editing", "tf-title-edit");
        if (n) n.title = host.querySelector(".tf-card-title").innerText.replace(/\s+/g, " ").trim();
        this.setEditable(host, false);
      }
      if (n) {
        for (const b of n.blocks) {
          if (b.html != null) b.html = sanitizeInline(b.html);
          if (b.body != null) b.body = sanitizeInline(b.body);
        }
        while (n.blocks.length) {
          const last = n.blocks[n.blocks.length - 1];
          if (last.type === "text" && !textLen(last.html) && !/tf-mention/.test(last.html)) n.blocks.pop();
          else break;
        }
        if (!n.title && !n.blocks.length && !n.childBoardId && !app.focus.isOpenFor(id)) app.removeNodes([id]);
      }
      if (window.getSelection) window.getSelection().removeAllRanges();
      if (n) app.refreshCard(id);
      app.render();
      app.commit();
      app.root.focus({ preventScroll: true });
    }
    setEditable(e, on) {
      e.querySelectorAll(".tf-bc").forEach((x) => {
        const v = on ? x.classList.contains("tf-code") ? "plaintext-only" : "true" : "false";
        try {
          x.contentEditable = v;
        } catch (err) {
          x.contentEditable = on ? "true" : "false";
        }
        x.spellcheck = on;
      });
      e.querySelector(".tf-card-title").contentEditable = on ? "true" : "false";
    }
    /** Re-rend la carte éditée après un changement de structure. */
    refresh() {
      if (this.cardId) this.app.refreshCard(this.cardId);
    }
    showTitle(selectAll) {
      if (!this.host) return;
      const t = this.host.querySelector(".tf-card-title");
      this.host.classList.add("tf-title-edit");
      if (selectAll && t.textContent) {
        t.focus({ preventScroll: true });
        const r = document.createRange();
        r.selectNodeContents(t);
        const s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      } else setCaret(t, "end");
    }
    // ------------------------------------------------ repérage
    blockCtx(target) {
      if (target && target.nodeType === 3) target = target.parentNode;
      const bc = target && target.closest && target.closest(".tf-bc");
      if (!bc || !this.host || !this.host.contains(bc)) return null;
      const be = bc.closest(".tf-block");
      const n = this.card;
      if (!be || !n) return null;
      const idx = n.blocks.findIndex((b) => b.id === be.dataset.bid);
      if (idx < 0) return null;
      return { bc, be, n, idx, b: n.blocks[idx], isBody: bc.classList.contains("tf-tog-body") };
    }
    /** Le curseur est-il sur la première (up) ou la dernière ligne du bloc ? */
    atEdge(bc, up) {
      return onEdgeLine(bc, up);
    }
    focusBlock(bid, pos) {
      const bc = this.host && this.host.querySelector(`.tf-block[data-bid="${bid}"] .tf-bc`);
      if (bc) setCaret(bc, pos);
    }
    sync(c) {
      const bc = c.bc;
      if (bc.innerHTML === "<br>") bc.innerHTML = "";
      if (c.b.type === "code") c.b.text = bc.textContent;
      else if (c.isBody) c.b.body = bc.innerHTML;
      else c.b.html = bc.innerHTML;
    }
    neighbor(bc, dir) {
      const list = Array.from(this.host.querySelectorAll(".tf-bc")).filter((x) => x.offsetParent !== null);
      return list[list.indexOf(bc) + dir] || null;
    }
    firstBlock() {
      const n = this.card;
      if (!n.blocks.length || isVoid(n.blocks[0].type)) {
        n.blocks.unshift(nb("text"));
        this.refresh();
      }
      return n.blocks[0];
    }
    // ------------------------------------------------ clavier
    onKey(e) {
      if (this.app.menu.key(e)) {
        e.stopPropagation();
        return;
      }
      if (this.bsel.onKey(e)) return;
      const t = e.target;
      if (t.classList && t.classList.contains("tf-card-title")) return this._titleKey(e, t);
      const c = this.blockCtx(t);
      if (!c) return;
      const { n, b, idx, bc } = c, isCode = b.type === "code";
      const collapsed = window.getSelection().isCollapsed;
      const refresh = () => this.refresh();
      if (e.key === "Enter" && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (isCode) return;
        e.preventDefault();
        if (c.isBody) {
          document.execCommand("insertLineBreak");
          return;
        }
        if (!textLen(bc.innerHTML) && !bc.querySelector(".tf-mention") && b.type !== "text") {
          if (b.indent) {
            b.indent--;
            if (!b.indent) delete b.indent;
          } else convertBlock(b, "text");
          refresh();
          this.focusBlock(b.id, "start");
          return;
        }
        const tail = sanitizeInline(extractTail(bc));
        this.sync(c);
        const type = isList(b.type) ? b.type : "text";
        const nbk = nb(type, tail, b.indent ? { indent: b.indent } : null);
        if (type === "toggle") {
          nbk.body = "";
          nbk.open = false;
        }
        n.blocks.splice(idx + 1, 0, nbk);
        refresh();
        this.focusBlock(nbk.id, "start");
        return;
      }
      if (e.key === "Enter" && e.shiftKey && !isCode) {
        e.preventDefault();
        document.execCommand("insertLineBreak");
        return;
      }
      if (e.key === "Backspace" && collapsed && !c.isBody && caretOffset(bc) === 0) {
        if (b.indent) {
          e.preventDefault();
          b.indent--;
          if (!b.indent) delete b.indent;
          refresh();
          this.focusBlock(b.id, "start");
          return;
        }
        if (b.type !== "text") {
          if (isCode && bc.textContent.length) return;
          e.preventDefault();
          convertBlock(b, "text");
          refresh();
          this.focusBlock(b.id, "start");
          return;
        }
        e.preventDefault();
        this.sync(c);
        const prev = n.blocks[idx - 1];
        if (!prev) {
          if (!textLen(b.html) && n.blocks.length > 1) {
            n.blocks.splice(idx, 1);
            refresh();
          }
          this.showTitle();
          return;
        }
        if (isVoid(prev.type)) {
          n.blocks.splice(idx - 1, 1);
          refresh();
          this.focusBlock(b.id, "start");
          return;
        }
        if (prev.type === "code") {
          if (!textLen(b.html)) {
            n.blocks.splice(idx, 1);
            refresh();
          }
          this.focusBlock(prev.id, "end");
          return;
        }
        const len = textLen(prev.html);
        prev.html = (prev.html || "") + (b.html || "");
        n.blocks.splice(idx, 1);
        refresh();
        this.focusBlock(prev.id, len);
        return;
      }
      if (e.key === "Delete" && collapsed && !c.isBody && !isCode && caretOffset(bc) === bc.textContent.length) {
        const next = n.blocks[idx + 1];
        if (!next || next.type === "code") return;
        e.preventDefault();
        this.sync(c);
        if (isVoid(next.type)) {
          n.blocks.splice(idx + 1, 1);
          refresh();
          this.focusBlock(b.id, "end");
          return;
        }
        const len = textLen(b.html);
        b.html = (b.html || "") + (next.html || "");
        n.blocks.splice(idx + 1, 1);
        refresh();
        this.focusBlock(b.id, len);
        return;
      }
      if (e.key === "Tab") {
        e.preventDefault();
        if (isCode) {
          document.execCommand("insertText", false, "  ");
          return;
        }
        if (!isList(b.type) || c.isBody) return;
        const off = caretOffset(bc);
        this.sync(c);
        b.indent = clamp((b.indent || 0) + (e.shiftKey ? -1 : 1), 0, 4);
        if (!b.indent) delete b.indent;
        refresh();
        this.focusBlock(b.id, off);
        return;
      }
      if ((e.key === "ArrowUp" || e.key === "ArrowLeft") && collapsed && !e.shiftKey) {
        if (e.key === "ArrowUp" ? !onEdgeLine(bc, true) : caretOffset(bc) !== 0) return;
        e.preventDefault();
        const up = this.neighbor(bc, -1);
        if (up) setCaret(up, "end");
        else this.showTitle();
        return;
      }
      if ((e.key === "ArrowDown" || e.key === "ArrowRight") && collapsed && !e.shiftKey) {
        if (e.key === "ArrowDown" ? !onEdgeLine(bc, false) : caretOffset(bc) !== bc.textContent.length) return;
        const down = this.neighbor(bc, 1);
        if (down) {
          e.preventDefault();
          setCaret(down, "start");
        }
      }
    }
    _titleKey(e, t) {
      if (e.key === "Enter" || e.key === "ArrowDown" && onEdgeLine(t, false)) {
        e.preventDefault();
        const n = this.card;
        if (e.key === "Enter" && n.blocks.length && textLen(n.blocks[0].html)) {
          n.blocks.unshift(nb("text"));
          this.refresh();
        }
        this.focusBlock(this.firstBlock().id, "start");
      } else if (e.key === "Backspace" && !t.textContent) {
        e.preventDefault();
        this.host.classList.remove("tf-title-edit");
        this.focusBlock(this.firstBlock().id, "start");
      }
    }
    // ------------------------------------------------ saisie
    onInput(e) {
      const c = this.blockCtx(e.target);
      if (!c) {
        this.app.onCardResize(this.cardId);
        return;
      }
      this.sync(c);
      const { b, bc, n } = c;
      if (b.type !== "code" && !c.isBody && e.inputType === "insertText") {
        const off = caretOffset(bc), txt = bc.textContent.replace(/ /g, " "), before = txt.slice(0, off);
        if (e.data === " ") {
          const md = MD_PREFIX[before.slice(0, -1)];
          if (md && b.type !== md && (b.type === "text" || isList(b.type) || /^h[1-3]$/.test(b.type))) {
            deleteTextRange(bc, 0, off);
            this.sync(c);
            convertBlock(b, md);
            if (md === "toggle") b.open = true;
            this.refresh();
            this.focusBlock(b.id, "start");
            return;
          }
        }
        if (e.data === "`" && txt === "```" && b.type === "text") {
          b.html = "";
          convertBlock(b, "code");
          b.text = "";
          this.refresh();
          this.focusBlock(b.id, "start");
          return;
        }
        if (e.data === "-" && txt === "---" && b.type === "text") {
          b.html = "";
          b.type = "divider";
          const after = nb("text");
          n.blocks.splice(c.idx + 1, 0, after);
          this.refresh();
          this.focusBlock(after.id, "start");
          return;
        }
        if (e.data === "/" || e.data === "@") {
          const ch = before.length >= 2 ? before.charAt(before.length - 2) : "";
          if (!ch || /\s/.test(ch)) this.openTrigger(e.data === "/" ? "slash" : "mention", c, off - 1);
        }
      }
      if (this.trigger) this.updateTrigger();
      this.app.onCardResize(this.cardId);
    }
    onPaste(e) {
      const cd = e.clipboardData;
      if (!cd) return;
      if (this.bsel.active) {
        e.preventDefault();
        const b = this.bsel.remove(true);
        if (b && !this._pasteBlocks(b.id, cd)) document.execCommand("insertText", false, (cd.getData("text/plain") || "").replace(/\s*\n\s*/g, " "));
        return;
      }
      if (e.target.classList && e.target.classList.contains("tf-card-title")) {
        e.preventDefault();
        document.execCommand("insertText", false, (cd.getData("text/plain") || "").replace(/\s+/g, " "));
        return;
      }
      const c = this.blockCtx(e.target);
      if (!c) return;
      if (cd.files && cd.files.length && /^image\//.test(cd.files[0].type)) {
        e.preventDefault();
        const info = { bid: c.b.id };
        readImage(cd.files[0]).then((src) => this.addImage(info, src, false));
        return;
      }
      const text = cd.getData("text/plain");
      if (text == null) return;
      e.preventDefault();
      if (!c.isBody && c.b.type !== "code" && this._pasteBlocks(c.b.id, cd)) return;
      if (c.b.type === "code" || c.isBody || !text.includes("\n")) {
        document.execCommand("insertText", false, text);
        this.sync(c);
        return;
      }
      const lines = text.replace(/\r/g, "").split("\n");
      document.execCommand("insertText", false, lines.shift());
      const tail = sanitizeInline(extractTail(c.bc));
      this.sync(c);
      let idx = c.idx, last = null;
      for (let ln of lines) {
        let type = "text";
        const m = ln.match(/^\s*(#{1,3}|[-*]|\d+\.|\[ ?\]|>)\s+(.*)$/);
        if (m) {
          type = MD_PREFIX[/^\d+\.$/.test(m[1]) ? "1." : m[1]] || "text";
          ln = m[2];
        }
        if (!ln.trim()) continue;
        last = nb(type, esc(ln));
        c.n.blocks.splice(++idx, 0, last);
      }
      if (!last) last = c.b;
      const pos = textLen(last.html);
      last.html = (last.html || "") + tail;
      this.refresh();
      this.focusBlock(last.id, pos);
    }
    /**
     * Colle des blocs copiés dans TeamFlow après le bloc `bid` (à sa place s'il est vide).
     * @returns {boolean} false si le presse-papiers ne vient pas d'une copie de blocs
     */
    _pasteBlocks(bid, cd) {
      const blocks = readBlocks(cd.getData("text/plain"));
      const n = this.card;
      if (!blocks || !blocks.length || !n) return false;
      let i = n.blocks.findIndex((b) => b.id === bid);
      const cur = n.blocks[i];
      const bc = this.host.querySelector(`.tf-block[data-bid="${bid}"] .tf-bc`);
      if (bc) {
        const c = this.blockCtx(bc);
        if (c) this.sync(c);
      }
      if (cur && cur.type === "text" && !textLen(cur.html) && !/tf-mention/.test(cur.html || "")) n.blocks.splice(i--, 1);
      n.blocks.splice(i + 1, 0, ...blocks);
      const last = blocks[blocks.length - 1];
      if (isVoid(last.type)) n.blocks.splice(i + 1 + blocks.length, 0, nb("text"));
      this.refresh();
      const after = isVoid(last.type) ? n.blocks[i + 1 + blocks.length] : last;
      this.focusBlock(after.id, "end");
      this.app.onCardResize(n.id);
      return true;
    }
    // ------------------------------------------------ menus « / » et « @ »
    openTrigger(kind, c, anchor2) {
      const meta = { bid: c.b.id, anchor: anchor2 };
      this.trigger = { kind, bid: c.b.id, anchor: anchor2 };
      if (kind === "slash") {
        this.app.menu.open(
          SLASH_ITEMS,
          caretRect(),
          (it, m) => {
            this.trigger = null;
            this.applySlash(it, m);
          },
          { header: "Tape pour filtrer", meta }
        );
      } else {
        this.app.menu.open(
          this.mentionItems(),
          caretRect(),
          (it, m) => {
            this.trigger = null;
            this.insertMention(it, m);
          },
          { header: "Lier une carte : tape son titre", empty: "Aucune carte trouv\xE9e", meta }
        );
      }
    }
    updateTrigger() {
      const tr = this.trigger;
      const bc = this.host && this.host.querySelector(`.tf-block[data-bid="${tr.bid}"] .tf-bc`);
      if (!bc || document.activeElement !== bc || !this.app.menu.isOpen) return this.closeTrigger();
      const off = caretOffset(bc), txt = bc.textContent;
      const ch = tr.kind === "slash" ? "/" : "@";
      if (off <= tr.anchor || txt.charAt(tr.anchor) !== ch) return this.closeTrigger();
      const f = txt.slice(tr.anchor + 1, off);
      if (f.length > 40 || /\s\s/.test(f) || tr.kind === "slash" && f.length > 24) return this.closeTrigger();
      this.app.menu.setFilter(f);
    }
    closeTrigger() {
      this.trigger = null;
      this.app.menu.close();
    }
    mentionItems() {
      const app = this.app;
      const cards = app.store.cards().filter((h) => h.node.id !== this.cardId);
      cards.sort((a, b) => (a.board.id === app.boardId ? 0 : 1) - (b.board.id === app.boardId ? 0 : 1));
      return cards.map((h) => ({
        id: h.node.id,
        label: cardSummary(h.node),
        hint: h.board.id === app.boardId ? "" : app.boardLabel(h.board),
        icon: h.node.childBoardId ? "\u25A6" : "@"
      }));
    }
    /** Retire le texte « /filtre » ou « @filtre » tapé avant l'insertion. */
    _consumeTrigger(info) {
      const n = this.card;
      const b = n && n.blocks.find((x) => x.id === info.bid);
      const bc = b && this.host.querySelector(`.tf-block[data-bid="${b.id}"] .tf-bc`);
      if (bc && info.anchor != null) {
        let end = caretOffset(bc);
        if (end <= info.anchor) end = info.anchor + 1;
        deleteTextRange(bc, info.anchor, end);
        this.sync({ bc, b, isBody: false });
      }
      return { n, b, bc };
    }
    insertMention(item, info) {
      const { b, bc } = this._consumeTrigger(info);
      if (!bc) return;
      bc.focus();
      document.execCommand(
        "insertHTML",
        false,
        `<span class="tf-mention" data-card="${item.id}" contenteditable="false">${esc(item.label)}</span>&nbsp;`
      );
      this.sync({ bc, b, isBody: false });
    }
    /** Ouvre le menu de liens « @ » depuis la position du curseur (bouton ou menu « / »). */
    startMention(info) {
      const { b, bc } = this._consumeTrigger(info);
      if (!bc) return;
      const c = this.blockCtx(bc);
      bc.focus();
      document.execCommand("insertText", false, "@");
      this.sync(c);
      this.openTrigger("mention", c, caretOffset(bc) - 1);
    }
    applySlash(item, info) {
      if (item.id === "mention") return this.startMention(info);
      const { n, b, bc } = this._consumeTrigger(info);
      if (!b) return;
      const idx = n.blocks.indexOf(b);
      if (item.id === "date" || item.id === "now") {
        const d = /* @__PURE__ */ new Date();
        const txt = d.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" }) + (item.id === "now" ? ", " + d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" }) : "");
        if (bc) {
          bc.focus();
          document.execCommand("insertText", false, txt);
          this.sync({ bc, b, isBody: false });
        }
        return;
      }
      const empty = !isVoid(b.type) && !blockText(b).trim() && !/tf-mention/.test(b.html || "");
      if (item.id === "image") {
        this.app.pickImage((src) => this.addImage({ bid: b.id }, src, true));
        return;
      }
      if (item.id === "divider") {
        const dv = nb("divider");
        if (empty && b.type === "text") n.blocks.splice(idx, 1, dv);
        else n.blocks.splice(idx + 1, 0, dv);
        const k = n.blocks.indexOf(dv);
        let nx = n.blocks[k + 1];
        if (!nx || isVoid(nx.type)) {
          nx = nb("text");
          n.blocks.splice(k + 1, 0, nx);
        }
        this.refresh();
        this.focusBlock(nx.id, "start");
        return;
      }
      let target = b;
      if (empty) convertBlock(b, item.id);
      else {
        target = nb(item.id, "", item.id === "code" ? { text: "" } : null);
        n.blocks.splice(idx + 1, 0, target);
      }
      if (item.id === "toggle") {
        target.open = true;
        if (target.body == null) target.body = "";
      }
      this.refresh();
      this.focusBlock(target.id, "start");
    }
    /** Ajoute une image après le bloc info.bid (ou à sa place s'il est vide). */
    addImage(info, src, replaceIfEmpty) {
      this.app.addImageBlock(this.cardId, info.bid, src, replaceIfEmpty);
    }
    // ------------------------------------------------ barre de mise en forme
    command(cmd) {
      if (cmd === "done") return this.app.focus.isOpen ? this.app.focus.close() : this.stop();
      const n = this.card;
      if (!n) return;
      const s = window.getSelection();
      let c = s.rangeCount ? this.blockCtx(s.anchorNode) : null;
      if (!c) {
        let last = n.blocks[n.blocks.length - 1];
        if (!last || isVoid(last.type)) {
          last = nb("text");
          n.blocks.push(last);
          this.refresh();
        }
        this.focusBlock(last.id, "end");
        c = this.blockCtx(window.getSelection().anchorNode);
        if (!c) return;
      }
      const info = { bid: c.b.id, anchor: null };
      switch (cmd) {
        case "bold":
          document.execCommand("bold");
          break;
        case "italic":
          document.execCommand("italic");
          break;
        case "strike":
          document.execCommand("strikeThrough");
          break;
        case "icode":
          if (c.b.type !== "code") document.execCommand("insertHTML", false, "<code>" + esc(s.toString() || "code") + "</code>");
          break;
        case "link":
          this._link(c);
          return;
        case "mention":
          this.startMention(info);
          return;
        case "insert": {
          const r = this.app.toolbars.buttonRect("insert");
          this.app.menu.open(SLASH_ITEMS, r, (it) => this.applySlash(it, info));
          return;
        }
        case "image":
          this.applySlash({ id: "image" }, info);
          return;
      }
      if (c.bc.isConnected) this.sync(c);
      this.app.onCardResize(this.cardId);
    }
    async _link(c) {
      const s = window.getSelection();
      const saved = s.rangeCount ? s.getRangeAt(0).cloneRange() : null;
      const url = await this.app.dialogs.prompt({ title: "Ajouter un lien", placeholder: "https://\u2026", value: "https://" });
      if (!url || url === "https://" || !c.bc.isConnected) return;
      c.bc.focus();
      if (saved) {
        s.removeAllRanges();
        s.addRange(saved);
      }
      if (!saved || saved.collapsed) document.execCommand("insertHTML", false, `<a href="${esc(url)}">${esc(url)}</a>`);
      else document.execCommand("createLink", false, url);
      this.sync(c);
    }
    // ------------------------------------------------ menu d'un bloc (clic sur la poignée)
    openBlockMenu(cardId, bid, rect) {
      const app = this.app, n = app.node(cardId);
      const b = n && n.blocks.find((x) => x.id === bid);
      if (!b) return;
      const items = [];
      if (!isVoid(b.type)) {
        items.push({ group: "Transformer en" });
        for (const t of TEXT_TYPES) items.push({ id: "to:" + t, label: BLOCK_TYPES[t].label, icon: BLOCK_TYPES[t].icon, on: b.type === t });
        items.push({ group: "Bloc" });
      }
      items.push({ id: "dup", label: "Dupliquer", icon: "\u29C9" }, { id: "del", label: "Supprimer", icon: "\u2715", danger: true });
      app.menu.open(items, rect, (it) => {
        const nn = app.node(cardId);
        const i = nn ? nn.blocks.findIndex((x) => x.id === bid) : -1;
        if (i < 0) return;
        const bb = nn.blocks[i];
        if (it.id === "dup") {
          const cb = clone(bb);
          cb.id = uid("k");
          nn.blocks.splice(i + 1, 0, cb);
        } else if (it.id === "del") nn.blocks.splice(i, 1);
        else {
          convertBlock(bb, it.id.slice(3));
          if (bb.type === "toggle") bb.open = true;
        }
        app.refreshCard(cardId);
        app.commit();
        if (this.cardId === cardId && it.id !== "del") this.focusBlock(bb.id, "end");
      });
    }
  };

  // src/features/focus.js
  var SPECIALS2 = ".tf-grip,.tf-check,.tf-tog,.tf-mention";
  var FocusView = class {
    constructor(app) {
      this.app = app;
      this.cardId = null;
      this.el = null;
      this.cardEl = null;
      this.scroll = null;
    }
    get isOpen() {
      return !!this.cardId;
    }
    isOpenFor(id) {
      return this.cardId === id;
    }
    open(cardId) {
      const app = this.app, n = app.node(cardId);
      if (!n || n.type !== "card") return;
      if (this.isOpen) this.close();
      if (app.editor.active) app.editor.stop();
      this.cardId = cardId;
      this.el = el("div", "tf-focus");
      this.el.innerHTML = `<div class="tf-focus-bar"><button class="tf-btn tf-focus-back" title="Revenir au sch\xE9ma (\xC9chap)">${I.close}<span>Revenir au sch\xE9ma</span></button><span class="tf-focus-where">${esc(app.boardLabel(app.board))}</span></div><div class="tf-focus-scroll"></div>`;
      this.scroll = this.el.querySelector(".tf-focus-scroll");
      this.cardEl = createCardEl(n);
      this.cardEl.classList.add("tf-focus-card");
      this.scroll.appendChild(this.cardEl);
      app.root.appendChild(this.el);
      app.root.classList.add("tf-focus-open");
      this.el.querySelector(".tf-focus-back").addEventListener("click", () => this.close());
      this.el.addEventListener("wheel", (e) => e.stopPropagation(), { passive: true });
      this.el.addEventListener("pointerdown", (e) => {
        app.menu.close();
        const sp = e.button === 0 ? e.target.closest(SPECIALS2) : null;
        if (sp && this.cardEl.contains(sp)) {
          e.preventDefault();
          app.interactions.handleSpecial(e, sp, this.scroll);
          return;
        }
        if (!e.target.closest(".tf-card") && !e.target.closest(".tf-focus-bar")) {
          e.preventDefault();
          const bcs = this.cardEl.querySelectorAll(".tf-block > .tf-bc");
          if (bcs.length) app.editor.focusBlock(bcs[bcs.length - 1].closest(".tf-block").dataset.bid, "end");
        }
      });
      this.render();
      app.editor.start(cardId, { host: this.cardEl });
      app.toolbars.render();
    }
    render() {
      const n = this.app.node(this.cardId);
      if (!n || !this.cardEl) return;
      renderCardContent(this.cardEl, n, this.app.renderCtx, true);
      this.cardEl.style.setProperty("--c", COLORS[n.color] || "var(--tf-neutral)");
    }
    close() {
      if (!this.isOpen) return;
      const app = this.app, id = this.cardId;
      this.cardId = null;
      if (app.editor.host === this.cardEl) app.editor.stop();
      this.el.remove();
      this.el = this.cardEl = this.scroll = null;
      app.root.classList.remove("tf-focus-open");
      if (app.node(id)) {
        app.refreshCard(id);
        app.selection.set([id]);
      }
      app.render();
    }
  };

  // src/storage/local.js
  function localStorageAdapter(key) {
    return {
      async load() {
        try {
          const s = localStorage.getItem(key);
          return s ? JSON.parse(s) : null;
        } catch (e) {
          return null;
        }
      },
      async save(doc) {
        localStorage.setItem(key, JSON.stringify(doc));
      }
    };
  }

  // src/app.js
  var App = class {
    /**
     * @param {HTMLElement} host
     * @param {{storage?: {load():Promise<object|null>, save(doc):Promise<void>}}} [opts]
     */
    constructor(host, opts = {}) {
      this.store = new Store(opts.storage || localStorageAdapter("teamflow"));
      this.id = uid("tf");
      this.boardId = null;
      this.mode = prefs.get("mode") || "mouse";
      this.theme = prefs.get("theme") || (window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark");
      this.spaceDown = false;
      this.drag = null;
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
        mentionLabel: (id) => {
          const hit = this.store.findCard(id);
          return hit ? cardSummary(hit.node) : null;
        },
        childCount: (bid) => {
          const b = this.store.board(bid);
          return b ? b.nodes.filter((x) => x.type === "card").length : 0;
        }
      };
      this._bind();
      bindKeyboard(this);
      this.minimap.setVisible(prefs.get("map") !== "0");
      this._renderModeUI();
      this._renderThemeUI();
      this.store.on((type, payload) => {
        if (type === "saving") this._status("Modifications\u2026");
        else if (type === "saved") this._status("Enregistr\xE9");
        else if (type === "save-error") {
          this._status("Erreur d'enregistrement", true);
          this.toast("Impossible d'enregistrer : " + (payload && payload.name === "QuotaExceededError" ? "stockage du navigateur plein (images trop lourdes ?)" : payload && payload.message || payload));
        }
      });
      this._status("Chargement\u2026");
      this.ready = this.store.load().then(() => {
        this._status("");
        this.openBoard(this.store.rootBoardId);
        return this;
      });
    }
    // ------------------------------------------------ DOM
    _buildDom(host) {
      const r = this.root = el("div", "tf-root");
      r.tabIndex = 0;
      r.dataset.mode = this.mode;
      r.dataset.theme = this.theme;
      this.vp = el("div", "tf-viewport");
      this.world = el("div", "tf-world");
      this.sectionsLayer = el("div", "tf-layer tf-sections");
      this.cardsLayer = el("div", "tf-layer tf-cards");
      this.svg = svgEl("svg", { class: "tf-edges", width: "1", height: "1" });
      this.world.append(this.sectionsLayer, this.svg, this.cardsLayer);
      this.marquee = el("div", "tf-marquee");
      this.vp.append(this.world, this.marquee);
      r.appendChild(this.vp);
      this.chrome = buildChrome(r);
      host.innerHTML = "";
      host.appendChild(r);
    }
    _bind() {
      const r = this.root;
      r.addEventListener("click", (e) => {
        const b = e.target.closest("[data-act]");
        if (b) return this.action(b.dataset.act);
        const c = e.target.closest(".tf-crumb");
        if (c) this.openBoard(c.dataset.board);
      });
      r.addEventListener("keydown", (e) => {
        if (this.editor.active) this.editor.onKey(e);
      });
      r.addEventListener("input", (e) => {
        if (this.editor.active) this.editor.onInput(e);
      });
      r.addEventListener("paste", (e) => {
        if (this.editor.active) this.editor.onPaste(e);
      });
      this.chrome.fileImport.addEventListener("change", () => {
        const f = this.chrome.fileImport.files[0];
        if (f) this.importJSON(f);
      });
      this.chrome.fileImage.addEventListener("change", () => {
        const f = this.chrome.fileImage.files[0], cb = this._imgCb;
        this._imgCb = null;
        if (f && cb) readImage(f).then(cb);
      });
      window.addEventListener("resize", () => {
        this.minimap.schedule();
        this.toolbars.position();
      });
    }
    isActive() {
      const a = document.activeElement;
      return a === document.body || a === null || this.root.contains(a);
    }
    // ------------------------------------------------ accès
    get board() {
      return this.store.board(this.boardId);
    }
    node(id) {
      return this.board.nodes.find((n) => n.id === id);
    }
    edge(id) {
      return this.board.edges.find((e) => e.id === id);
    }
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
        const r = this.nodes.rect(n), top = n.type === "section" ? r.y - 44 : r.y;
        x1 = Math.min(x1, r.x);
        y1 = Math.min(y1, top);
        x2 = Math.max(x2, r.x + r.w);
        y2 = Math.max(y2, r.y + r.h);
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
      if (id) this.edges.updateFor(/* @__PURE__ */ new Set([id]));
      this.minimap.schedule();
      this.toolbars.position();
    }
    onViewChange() {
      this.chrome.zoomLabel.textContent = Math.round(this.viewport.view.z * 100) + "%";
      this.minimap.schedule();
      this.toolbars.position();
      this.store.saveViews();
    }
    commit() {
      return this.store.commit();
    }
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
      if (v.fresh) {
        delete v.fresh;
        this.fit(false);
      } else this.viewport.apply();
    }
    goUp() {
      const b = this.board;
      if (!b.parent || !this.store.board(b.parent.boardId)) return;
      const nodeId = b.parent.nodeId;
      this.openBoard(b.parent.boardId);
      if (this.node(nodeId)) {
        this.selection.set([nodeId]);
        this.render();
      }
    }
    /** Va à une carte, quel que soit son whiteboard, et la met en évidence. */
    revealCard(cardId) {
      const hit = this.store.findCard(cardId);
      if (!hit) {
        this.toast("Cette carte n'existe plus");
        return;
      }
      if (hit.board.id !== this.boardId) this.openBoard(hit.board.id);
      const n = this.node(cardId), r = this.nodes.rect(n);
      if (this.viewport.view.z < 0.6) this.viewport.view.z = 0.8;
      this.viewport.centerOn(r.x + r.w / 2, r.y + r.h / 2, true);
      this.selection.set([cardId]);
      this.render();
      const e = this.nodes.get(cardId);
      if (e) {
        e.classList.remove("tf-flash");
        void e.offsetWidth;
        e.classList.add("tf-flash");
      }
    }
    fit() {
      this.viewport.fit(this.bounds(), true);
    }
    _renderCrumbs() {
      const chain = [];
      let b = this.board, guard = 0;
      while (b && guard++ < 50) {
        chain.unshift(b);
        b = b.parent ? this.store.board(b.parent.boardId) : null;
      }
      const span = document.createElement("span");
      this.chrome.crumbs.innerHTML = chain.map((x, i) => {
        span.textContent = this.boardLabel(x);
        return (i ? '<span class="tf-crumb-sep">/</span>' : "") + `<button class="tf-crumb${i === chain.length - 1 ? " tf-current" : ""}" data-board="${x.id}">${span.innerHTML}</button>`;
      }).join("");
    }
    // ------------------------------------------------ sélection
    select(ids) {
      this.selection.set(ids);
      this.render();
    }
    clearSelection() {
      if (!this.selection.empty) {
        this.selection.clear();
        this.render();
      }
    }
    selectEdge(id) {
      this.selection.setEdge(id);
      this.render();
    }
    /** Rectangle écran de la sélection (pour placer la barre d'outils). */
    selectionScreenRect() {
      const ed = this.selection.edge && this.edge(this.selection.edge);
      if (ed) {
        const a = this.node(ed.from), b2 = this.node(ed.to);
        if (!a || !b2) return null;
        const ra = this.nodes.rect(a), rb = this.nodes.rect(b2);
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
      if (n.type !== "card" || i < 0 || i === b.nodes.length - 1) return;
      b.nodes.splice(i, 1);
      b.nodes.push(n);
      this.nodes.bringToFront(n);
    }
    addCard(x, y, props, edit) {
      const n = newCard(x, y, props);
      this.board.nodes.push(n);
      this.selection.set([n.id]);
      this.render();
      if (edit) this.editor.start(n.id);
      else this.commit();
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
      const n = this.addCard(c.x - 130, c.y - 40, { w: 260, title: "Nouveau whiteboard" }, false);
      this.makeBoard(n.id);
      this.editor.start(n.id, { focusTitle: true });
    }
    makeBoard(id) {
      const n = this.node(id);
      if (!n || n.childBoardId) return;
      const b = newBoard(n.title || "Sous-whiteboard", { boardId: this.boardId, nodeId: n.id });
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
        this.render();
        this.commit();
        return;
      }
      if (!this.selection.size) return;
      const boards = this.selection.nodes().filter((n) => {
        const cb = n.childBoardId && this.store.board(n.childBoardId);
        return cb && cb.nodes.length;
      });
      if (boards.length) {
        const ok = await this.dialogs.confirm({
          title: "Supprimer le sous-whiteboard ?",
          message: `\xAB ${cardSummary(boards[0])} \xBB${boards.length > 1 ? ` et ${boards.length - 1} autre(s)` : ""} contient des cartes. Elles seront supprim\xE9es aussi.`,
          ok: "Supprimer",
          danger: true
        });
        if (!ok) return;
      }
      this.removeNodes(Array.from(this.selection.ids));
      this.render();
      this.commit();
    }
    _cloneBoardRec(bid, parent) {
      const src = this.store.board(bid);
      if (!src) return null;
      const b = clone(src), map = {};
      b.id = uid("b");
      b.parent = parent;
      for (const n of b.nodes) {
        const old = n.id;
        n.id = uid("n");
        map[old] = n.id;
      }
      for (const e of b.edges) {
        e.id = uid("e");
        e.from = map[e.from];
        e.to = map[e.to];
      }
      this.store.addBoard(b);
      for (const n of b.nodes) if (n.childBoardId) n.childBoardId = this._cloneBoardRec(n.childBoardId, { boardId: b.id, nodeId: n.id });
      return b.id;
    }
    duplicateSelection() {
      if (!this.selection.size) return;
      const b = this.board, map = {}, added = [];
      for (const n of this.selection.nodes()) {
        const c = clone(n);
        c.id = uid("n");
        c.x += 30;
        c.y += 30;
        if (c.blocks) for (const k of c.blocks) k.id = uid("k");
        map[n.id] = c.id;
        if (c.childBoardId) c.childBoardId = this._cloneBoardRec(n.childBoardId, { boardId: this.boardId, nodeId: c.id });
        added.push(c);
      }
      for (const c of added) {
        if (c.type === "section") b.nodes.unshift(c);
        else b.nodes.push(c);
      }
      for (const e of b.edges.slice()) {
        if (map[e.from] && map[e.to]) b.edges.push(Object.assign(clone(e), { id: uid("e"), from: map[e.from], to: map[e.to] }));
      }
      this.select(added.map((c) => c.id));
      this.commit();
    }
    setColor(c) {
      const ed = this.selection.edge && this.edge(this.selection.edge);
      if (ed) ed.color = c;
      else for (const n of this.selection.nodes()) n.color = c;
      for (const n of this.selection.nodes()) this.refreshCard(n.id);
      this.render();
      this.commit();
    }
    async editEdgeLabel(id) {
      const e = this.edge(id);
      if (!e) return;
      const v = await this.dialogs.prompt({ title: "Libell\xE9 de la fl\xE8che", value: e.label || "", placeholder: "ex. : d\xE9pend de" });
      if (v === null || !this.edge(id)) return;
      e.label = v.trim();
      this.render();
      this.commit();
    }
    toggleCollapse(id) {
      const n = this.node(id);
      if (!n || n.type !== "card") return;
      if (this.editor.cardId === id) this.editor.stop();
      n.collapsed = !n.collapsed;
      if (!n.collapsed) delete n.collapsed;
      this.refreshCard(id);
      this.render();
      this.commit();
    }
    /** Coche une case ou déplie une liste (cible : .tf-check ou .tf-tog). */
    toggleBlockFlag(target) {
      const be = target.closest(".tf-block"), ce = be && be.closest(".tf-card");
      const n = ce && this.node(ce.dataset.id);
      const b = n && n.blocks.find((x) => x.id === be.dataset.bid);
      if (!b) return;
      if (target.classList.contains("tf-check")) {
        if (b.checked) delete b.checked;
        else b.checked = true;
      } else b.open = !b.open;
      this.refreshCard(n.id);
      this.commit();
    }
    /** Insère une image après le bloc `bid` (ou à sa place s'il est vide). */
    addImageBlock(cardId, bid, src, replaceIfEmpty) {
      const n = this.node(cardId);
      if (!n) return;
      const img = nb("image", "", { asset: this.store.addAsset(src) });
      const i = n.blocks.findIndex((x) => x.id === bid);
      if (i < 0) n.blocks.push(img);
      else if (replaceIfEmpty && n.blocks[i].type === "text" && !n.blocks[i].html) n.blocks.splice(i, 1, img);
      else n.blocks.splice(i + 1, 0, img);
      const j = n.blocks.indexOf(img);
      let after = n.blocks[j + 1];
      const editing = this.editor.cardId === cardId;
      if (editing && (!after || isVoid(after.type))) {
        after = nb("text");
        n.blocks.splice(j + 1, 0, after);
      }
      this.refreshCard(cardId);
      if (editing && after) this.editor.focusBlock(after.id, "start");
      else this.commit();
    }
    pickImage(cb) {
      this._imgCb = cb;
      this.chrome.fileImage.value = "";
      this.chrome.fileImage.click();
    }
    editSectionTitle(id) {
      const e = this.nodes.get(id), n = this.node(id);
      if (!e || !n) return;
      const lab = e.querySelector(".tf-section-label");
      lab.contentEditable = "true";
      lab.focus();
      const rg = document.createRange();
      rg.selectNodeContents(lab);
      const s = window.getSelection();
      s.removeAllRanges();
      s.addRange(rg);
      const done = (cancel) => {
        lab.removeEventListener("blur", onBlur);
        lab.removeEventListener("keydown", onKey);
        lab.contentEditable = "false";
        if (!cancel) n.title = lab.innerText.replace(/\s+/g, " ").trim() || "Section";
        lab.textContent = n.title;
        this.render();
        this.commit();
        this.root.focus({ preventScroll: true });
      };
      const onBlur = () => done(false);
      const onKey = (ev) => {
        ev.stopPropagation();
        if (ev.key === "Enter") {
          ev.preventDefault();
          lab.blur();
        }
        if (ev.key === "Escape") {
          ev.preventDefault();
          lab.removeEventListener("blur", onBlur);
          done(true);
        }
      };
      lab.addEventListener("blur", onBlur);
      lab.addEventListener("keydown", onKey);
    }
    // ------------------------------------------------ annulation
    undo() {
      this._history("undo");
    }
    redo() {
      this._history("redo");
    }
    _history(dir) {
      if (this.focus.isOpen) this.focus.close();
      if (this.editor.active) this.editor.stop();
      if (!(dir === "undo" ? this.store.undo() : this.store.redo())) return;
      this.selection.clear();
      if (!this.board) this.openBoard(this.store.rootBoardId);
      else {
        this.nodes.clear();
        this.edges.clear();
        this.render();
        this._renderCrumbs();
      }
    }
    // ------------------------------------------------ actions des barres
    action(a) {
      const c = this._centerWorld();
      switch (a) {
        case "add-card":
          this.addCard(c.x - 140 + (Math.random() * 40 - 20), c.y - 50 + (Math.random() * 40 - 20), null, true);
          break;
        case "add-section":
          this.addSection();
          break;
        case "add-board":
          this.addBoardCard();
          break;
        case "undo":
          this.undo();
          break;
        case "redo":
          this.redo();
          break;
        case "export":
          this.exportJSON();
          break;
        case "import":
          this.chrome.fileImport.value = "";
          this.chrome.fileImport.click();
          break;
        case "reset":
          this.resetDemo();
          break;
        case "toggle-map":
          this.minimap.setVisible(!this.minimap.visible);
          prefs.set("map", this.minimap.visible ? "1" : "0");
          this.chrome.mapBtn.classList.toggle("tf-on", this.minimap.visible);
          break;
        case "toggle-mode":
          this.setMode(this.mode === "mouse" ? "touchpad" : "mouse");
          break;
        case "toggle-theme":
          this.setTheme(this.theme === "dark" ? "light" : "dark");
          break;
        case "zoom-in":
          this.viewport.zoomCenter(1.25);
          break;
        case "zoom-out":
          this.viewport.zoomCenter(0.8);
          break;
        case "zoom-reset":
          this.viewport.zoomCenter(1 / this.viewport.view.z);
          break;
        case "fit":
          this.fit();
          break;
      }
    }
    selectionAction(a) {
      const id = this.selection.first(), ed = this.selection.edge && this.edge(this.selection.edge);
      if (a.startsWith("dir-") && ed) {
        ed.dir = a.slice(4);
        if (ed.dir === "end") delete ed.dir;
        this.render();
        this.commit();
        return;
      }
      if (a.startsWith("style-") && ed) {
        ed.style = a.slice(6);
        if (ed.style === "solid") delete ed.style;
        this.render();
        this.commit();
        return;
      }
      switch (a) {
        case "delete":
          this.deleteSelection();
          break;
        case "duplicate":
          this.duplicateSelection();
          break;
        case "to-board":
          this.makeBoard(id);
          this.toast("Carte transform\xE9e en sous-whiteboard : clique sur \xAB Ouvrir \xBB");
          break;
        case "open": {
          const n = this.node(id);
          if (n && n.childBoardId) this.openBoard(n.childBoardId);
          break;
        }
        case "focus":
          this.focus.open(id);
          break;
        case "label":
          if (ed) this.editEdgeLabel(ed.id);
          break;
        case "swap":
          if (ed) {
            [ed.from, ed.to] = [ed.to, ed.from];
            this.render();
            this.commit();
          }
          break;
      }
    }
    setMode(m) {
      this.mode = m;
      prefs.set("mode", m);
      this.root.dataset.mode = m;
      this._renderModeUI();
      this.toast(m === "mouse" ? "Mode souris : molette pour zoomer, glisser le fond pour se d\xE9placer" : "Mode touchpad : deux doigts pour se d\xE9placer, pincer pour zoomer, glisser pour s\xE9lectionner");
    }
    _renderModeUI() {
      const m = this.mode, c = this.chrome;
      c.modeBtn.innerHTML = m === "mouse" ? I.mouse : I.touchpad;
      c.modeBtn.title = m === "mouse" ? "Mode souris (cliquer pour passer en mode touchpad)" : "Mode touchpad (cliquer pour passer en mode souris)";
      c.hint.textContent = m === "mouse" ? "Molette : zoom \xB7 Glisser le fond : se d\xE9placer \xB7 Double-clic : nouvelle carte" : "Deux doigts : se d\xE9placer \xB7 Pincer : zoom \xB7 Glisser le fond : s\xE9lection \xB7 Double-clic : nouvelle carte";
      c.mapBtn.classList.toggle("tf-on", prefs.get("map") !== "0");
    }
    setTheme(t) {
      this.theme = t;
      prefs.set("theme", t);
      this.root.dataset.theme = t;
      this._renderThemeUI();
      this.minimap.schedule();
    }
    _renderThemeUI() {
      const dark = this.theme === "dark", b = this.chrome.themeBtn;
      b.innerHTML = dark ? I.moon : I.sun;
      b.title = dark ? "Th\xE8me sombre (cliquer pour passer en clair)" : "Th\xE8me clair (cliquer pour passer en sombre)";
    }
    // ------------------------------------------------ import / export
    exportJSON() {
      const blob = new Blob([JSON.stringify(this.store.toJSON(), null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "teamflow-" + (/* @__PURE__ */ new Date()).toISOString().slice(0, 10) + ".json";
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 1e3);
    }
    importJSON(file) {
      const fr = new FileReader();
      fr.onload = () => {
        try {
          const d = JSON.parse(fr.result);
          if (!isValidDoc(d)) throw new Error("format inconnu");
          this._replaceDoc(d);
          this.toast("Whiteboard import\xE9");
        } catch (e) {
          this.toast("Import impossible : " + e.message);
        }
      };
      fr.readAsText(file);
    }
    async resetDemo() {
      const ok = await this.dialogs.confirm({
        title: "R\xE9initialiser la d\xE9mo ?",
        message: "Tout le contenu actuel sera remplac\xE9 par la d\xE9mo de d\xE9part. Pense \xE0 exporter si tu veux le garder.",
        ok: "R\xE9initialiser",
        danger: true
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
      t.classList.add("tf-show");
      clearTimeout(this._toastT);
      this._toastT = setTimeout(() => t.classList.remove("tf-show"), 2600);
    }
    _status(text, err) {
      const s = this.chrome.status;
      s.textContent = text;
      s.classList.toggle("tf-err", !!err);
    }
  };

  // src/storage/rest.js
  function restAdapter(url, opts = {}) {
    const headers = { "Content-Type": "application/json" };
    if (opts.nonce) headers["X-WP-Nonce"] = opts.nonce;
    return {
      async load() {
        const r = await fetch(url, { headers, credentials: "same-origin" });
        if (r.status === 404) return null;
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      },
      async save(doc) {
        const r = await fetch(url, { method: "POST", headers, credentials: "same-origin", body: JSON.stringify(doc) });
        if (!r.ok) throw new Error("HTTP " + r.status);
      }
    };
  }

  // src/storage/index.js
  var storage = { local: localStorageAdapter, rest: restAdapter };

  // src/index.js
  var version = "0.4.0";
  function mount(el2, opts) {
    return new App(el2, opts);
  }
  return __toCommonJS(src_exports);
})();
//# sourceMappingURL=teamflow.js.map
