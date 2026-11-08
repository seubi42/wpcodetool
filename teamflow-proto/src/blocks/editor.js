/**
 * Éditeur de carte : édition des blocs (clavier, Markdown, collage),
 * menus « / » et « @ », barre de mise en forme.
 * Il travaille sur un élément hôte : la carte du canevas ou la vue plein écran.
 */
import { nb } from '../core/model.js';
import { clamp, esc, clone, uid } from '../core/util.js';
import { BLOCK_TYPES, TEXT_TYPES, SLASH_ITEMS, MD_PREFIX, isVoid, isList } from './types.js';
import { sanitizeInline } from './sanitize.js';
import { blockText, textLen, convertBlock, cardSummary } from './model.js';
import { caretOffset, setCaret, deleteTextRange, extractTail, caretRect, onEdgeLine, rangeFromPoint } from './caret.js';
import { readImage } from '../ui/images.js';
import { BlockSelection } from './blockselect.js';
import { readBlocks } from './clipboard.js';

export class CardEditor {
  /** @param {import('../app.js').App} app */
  constructor(app) {
    this.app = app;
    this.cardId = null;
    this.host = null;
    this.trigger = null; // { kind: 'slash'|'mention', bid, anchor }
    this.bsel = new BlockSelection(this);
  }

  get active() { return !!this.cardId; }
  get card() { return this.cardId ? this.app.node(this.cardId) : null; }
  isEditingIn(el) { return !!this.host && this.host === el; }

  // ------------------------------------------------ cycle de vie
  /**
   * @param {string} cardId
   * @param {{evt?: MouseEvent, host?: HTMLElement, focusTitle?: boolean}} [opts]
   */
  start(cardId, opts = {}) {
    const app = this.app, n = app.node(cardId);
    const host = opts.host || app.nodes.get(cardId);
    if (!n || n.type !== 'card' || !host) return;
    if (this.active && (this.cardId !== cardId || this.host !== host)) this.stop();
    this.cardId = cardId;
    this.host = host;
    app.selection.set([cardId]);
    if (host === app.nodes.get(cardId)) app.bringToFront(n);
    if (!n.blocks.length || isVoid(n.blocks[n.blocks.length - 1].type)) n.blocks.push(nb('text'));
    host.classList.add('tf-editing');
    app.refreshCard(cardId);
    app.render();

    const title = host.querySelector('.tf-card-title');
    if (opts.focusTitle) { this.showTitle(true); return; }
    if (opts.evt) {
      const range = rangeFromPoint(opts.evt.clientX, opts.evt.clientY);
      const node = range && (range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentNode);
      const target = node && node.closest && node.closest('.tf-bc, .tf-card-title');
      if (target && host.contains(target)) {
        target.focus({ preventScroll: true });
        const s = window.getSelection();
        s.removeAllRanges();
        s.addRange(range);
        return;
      }
    }
    const bcs = host.querySelectorAll('.tf-block > .tf-bc, .tf-tog-wrap > .tf-bc:first-child');
    setCaret(bcs[bcs.length - 1] || title, 'end');
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
      host.classList.remove('tf-editing', 'tf-title-edit');
      if (n) n.title = host.querySelector('.tf-card-title').innerText.replace(/\s+/g, ' ').trim();
      this.setEditable(host, false);
    }
    if (n) {
      for (const b of n.blocks) {
        if (b.html != null) b.html = sanitizeInline(b.html);
        if (b.body != null) b.body = sanitizeInline(b.body);
      }
      while (n.blocks.length) {
        const last = n.blocks[n.blocks.length - 1];
        if (last.type === 'text' && !textLen(last.html) && !/tf-mention/.test(last.html)) n.blocks.pop(); else break;
      }
      // une carte vide (ni titre ni contenu) disparaît
      if (!n.title && !n.blocks.length && !n.childBoardId && !app.focus.isOpenFor(id)) app.removeNodes([id]);
    }
    if (window.getSelection) window.getSelection().removeAllRanges();
    if (n) app.refreshCard(id);
    app.render();
    app.commit();
    app.root.focus({ preventScroll: true });
  }

  setEditable(e, on) {
    e.querySelectorAll('.tf-bc').forEach((x) => {
      const v = on ? (x.classList.contains('tf-code') ? 'plaintext-only' : 'true') : 'false';
      try { x.contentEditable = v; } catch (err) { x.contentEditable = on ? 'true' : 'false'; }
      x.spellcheck = on;
    });
    e.querySelector('.tf-card-title').contentEditable = on ? 'true' : 'false';
  }

  /** Re-rend la carte éditée après un changement de structure. */
  refresh() {
    if (this.cardId) this.app.refreshCard(this.cardId);
  }

  showTitle(selectAll) {
    if (!this.host) return;
    const t = this.host.querySelector('.tf-card-title');
    this.host.classList.add('tf-title-edit');
    if (selectAll && t.textContent) {
      t.focus({ preventScroll: true });
      const r = document.createRange();
      r.selectNodeContents(t);
      const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    } else setCaret(t, 'end');
  }

  // ------------------------------------------------ repérage
  blockCtx(target) {
    if (target && target.nodeType === 3) target = target.parentNode;
    const bc = target && target.closest && target.closest('.tf-bc');
    if (!bc || !this.host || !this.host.contains(bc)) return null;
    const be = bc.closest('.tf-block');
    const n = this.card;
    if (!be || !n) return null;
    const idx = n.blocks.findIndex((b) => b.id === be.dataset.bid);
    if (idx < 0) return null;
    return { bc, be, n, idx, b: n.blocks[idx], isBody: bc.classList.contains('tf-tog-body') };
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
    if (bc.innerHTML === '<br>') bc.innerHTML = '';
    if (c.b.type === 'code') c.b.text = bc.textContent;
    else if (c.isBody) c.b.body = bc.innerHTML;
    else c.b.html = bc.innerHTML;
  }

  neighbor(bc, dir) {
    const list = Array.from(this.host.querySelectorAll('.tf-bc')).filter((x) => x.offsetParent !== null);
    return list[list.indexOf(bc) + dir] || null;
  }

  firstBlock() {
    const n = this.card;
    if (!n.blocks.length || isVoid(n.blocks[0].type)) { n.blocks.unshift(nb('text')); this.refresh(); }
    return n.blocks[0];
  }

  // ------------------------------------------------ clavier
  onKey(e) {
    if (this.app.menu.key(e)) { e.stopPropagation(); return; }
    if (this.bsel.onKey(e)) return;
    const t = e.target;
    if (t.classList && t.classList.contains('tf-card-title')) return this._titleKey(e, t);
    const c = this.blockCtx(t);
    if (!c) return;
    const { n, b, idx, bc } = c, isCode = b.type === 'code';
    const collapsed = window.getSelection().isCollapsed;
    const refresh = () => this.refresh();

    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
      if (isCode) return; // retour à la ligne dans le code
      e.preventDefault();
      if (c.isBody) { document.execCommand('insertLineBreak'); return; }
      if (!textLen(bc.innerHTML) && !bc.querySelector('.tf-mention') && b.type !== 'text') {
        if (b.indent) { b.indent--; if (!b.indent) delete b.indent; } else convertBlock(b, 'text');
        refresh(); this.focusBlock(b.id, 'start');
        return;
      }
      const tail = sanitizeInline(extractTail(bc));
      this.sync(c);
      const type = isList(b.type) ? b.type : 'text';
      const nbk = nb(type, tail, b.indent ? { indent: b.indent } : null);
      if (type === 'toggle') { nbk.body = ''; nbk.open = false; }
      n.blocks.splice(idx + 1, 0, nbk);
      refresh(); this.focusBlock(nbk.id, 'start');
      return;
    }
    if (e.key === 'Enter' && e.shiftKey && !isCode) { e.preventDefault(); document.execCommand('insertLineBreak'); return; }

    if (e.key === 'Backspace' && collapsed && !c.isBody && caretOffset(bc) === 0) {
      if (b.indent) { e.preventDefault(); b.indent--; if (!b.indent) delete b.indent; refresh(); this.focusBlock(b.id, 'start'); return; }
      if (b.type !== 'text') {
        if (isCode && bc.textContent.length) return;
        e.preventDefault(); convertBlock(b, 'text'); refresh(); this.focusBlock(b.id, 'start');
        return;
      }
      e.preventDefault();
      this.sync(c);
      const prev = n.blocks[idx - 1];
      if (!prev) {
        if (!textLen(b.html) && n.blocks.length > 1) { n.blocks.splice(idx, 1); refresh(); }
        this.showTitle();
        return;
      }
      if (isVoid(prev.type)) { n.blocks.splice(idx - 1, 1); refresh(); this.focusBlock(b.id, 'start'); return; }
      if (prev.type === 'code') {
        if (!textLen(b.html)) { n.blocks.splice(idx, 1); refresh(); }
        this.focusBlock(prev.id, 'end');
        return;
      }
      const len = textLen(prev.html);
      prev.html = (prev.html || '') + (b.html || '');
      n.blocks.splice(idx, 1);
      refresh(); this.focusBlock(prev.id, len);
      return;
    }

    if (e.key === 'Delete' && collapsed && !c.isBody && !isCode && caretOffset(bc) === bc.textContent.length) {
      const next = n.blocks[idx + 1];
      if (!next || next.type === 'code') return;
      e.preventDefault();
      this.sync(c);
      if (isVoid(next.type)) { n.blocks.splice(idx + 1, 1); refresh(); this.focusBlock(b.id, 'end'); return; }
      const len = textLen(b.html);
      b.html = (b.html || '') + (next.html || '');
      n.blocks.splice(idx + 1, 1);
      refresh(); this.focusBlock(b.id, len);
      return;
    }

    if (e.key === 'Tab') {
      e.preventDefault();
      if (isCode) { document.execCommand('insertText', false, '  '); return; }
      if (!isList(b.type) || c.isBody) return;
      const off = caretOffset(bc);
      this.sync(c);
      b.indent = clamp((b.indent || 0) + (e.shiftKey ? -1 : 1), 0, 4);
      if (!b.indent) delete b.indent;
      refresh(); this.focusBlock(b.id, off);
      return;
    }

    if ((e.key === 'ArrowUp' || e.key === 'ArrowLeft') && collapsed && !e.shiftKey) {
      if (e.key === 'ArrowUp' ? !onEdgeLine(bc, true) : caretOffset(bc) !== 0) return;
      e.preventDefault();
      const up = this.neighbor(bc, -1);
      if (up) setCaret(up, 'end'); else this.showTitle();
      return;
    }
    if ((e.key === 'ArrowDown' || e.key === 'ArrowRight') && collapsed && !e.shiftKey) {
      if (e.key === 'ArrowDown' ? !onEdgeLine(bc, false) : caretOffset(bc) !== bc.textContent.length) return;
      const down = this.neighbor(bc, 1);
      if (down) { e.preventDefault(); setCaret(down, 'start'); }
    }
  }

  _titleKey(e, t) {
    if (e.key === 'Enter' || (e.key === 'ArrowDown' && onEdgeLine(t, false))) {
      e.preventDefault();
      const n = this.card;
      if (e.key === 'Enter' && n.blocks.length && textLen(n.blocks[0].html)) { n.blocks.unshift(nb('text')); this.refresh(); }
      this.focusBlock(this.firstBlock().id, 'start');
    } else if (e.key === 'Backspace' && !t.textContent) {
      e.preventDefault();
      this.host.classList.remove('tf-title-edit');
      this.focusBlock(this.firstBlock().id, 'start');
    }
  }

  // ------------------------------------------------ saisie
  onInput(e) {
    const c = this.blockCtx(e.target);
    if (!c) { this.app.onCardResize(this.cardId); return; }
    this.sync(c);
    const { b, bc, n } = c;
    if (b.type !== 'code' && !c.isBody && e.inputType === 'insertText') {
      const off = caretOffset(bc), txt = bc.textContent.replace(/ /g, ' '), before = txt.slice(0, off);
      if (e.data === ' ') {
        const md = MD_PREFIX[before.slice(0, -1)];
        if (md && b.type !== md && (b.type === 'text' || isList(b.type) || /^h[1-3]$/.test(b.type))) {
          deleteTextRange(bc, 0, off);
          this.sync(c);
          convertBlock(b, md);
          if (md === 'toggle') b.open = true;
          this.refresh(); this.focusBlock(b.id, 'start');
          return;
        }
      }
      if (e.data === '`' && txt === '```' && b.type === 'text') {
        b.html = ''; convertBlock(b, 'code'); b.text = '';
        this.refresh(); this.focusBlock(b.id, 'start');
        return;
      }
      if (e.data === '-' && txt === '---' && b.type === 'text') {
        b.html = ''; b.type = 'divider';
        const after = nb('text');
        n.blocks.splice(c.idx + 1, 0, after);
        this.refresh(); this.focusBlock(after.id, 'start');
        return;
      }
      if (e.data === '/' || e.data === '@') {
        const ch = before.length >= 2 ? before.charAt(before.length - 2) : '';
        if (!ch || /\s/.test(ch)) this.openTrigger(e.data === '/' ? 'slash' : 'mention', c, off - 1);
      }
    }
    if (this.trigger) this.updateTrigger();
    this.app.onCardResize(this.cardId);
  }

  onPaste(e) {
    const cd = e.clipboardData;
    if (!cd) return;
    if (this.bsel.active) {
      // coller sur des blocs sélectionnés : ils sont remplacés
      e.preventDefault();
      const b = this.bsel.remove(true);
      if (b && !this._pasteBlocks(b.id, cd)) document.execCommand('insertText', false, (cd.getData('text/plain') || '').replace(/\s*\n\s*/g, ' '));
      return;
    }
    if (e.target.classList && e.target.classList.contains('tf-card-title')) {
      e.preventDefault();
      document.execCommand('insertText', false, (cd.getData('text/plain') || '').replace(/\s+/g, ' '));
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
    const text = cd.getData('text/plain');
    if (text == null) return;
    e.preventDefault();
    if (!c.isBody && c.b.type !== 'code' && this._pasteBlocks(c.b.id, cd)) return;
    if (c.b.type === 'code' || c.isBody || !text.includes('\n')) {
      document.execCommand('insertText', false, text);
      this.sync(c);
      return;
    }
    const lines = text.replace(/\r/g, '').split('\n');
    document.execCommand('insertText', false, lines.shift());
    const tail = sanitizeInline(extractTail(c.bc));
    this.sync(c);
    let idx = c.idx, last = null;
    for (let ln of lines) {
      let type = 'text';
      const m = ln.match(/^\s*(#{1,3}|[-*]|\d+\.|\[ ?\]|>)\s+(.*)$/);
      if (m) { type = MD_PREFIX[/^\d+\.$/.test(m[1]) ? '1.' : m[1]] || 'text'; ln = m[2]; }
      if (!ln.trim()) continue;
      last = nb(type, esc(ln));
      c.n.blocks.splice(++idx, 0, last);
    }
    if (!last) last = c.b;
    const pos = textLen(last.html);
    last.html = (last.html || '') + tail;
    this.refresh();
    this.focusBlock(last.id, pos);
  }

  /**
   * Colle des blocs copiés dans TeamFlow après le bloc `bid` (à sa place s'il est vide).
   * @returns {boolean} false si le presse-papiers ne vient pas d'une copie de blocs
   */
  _pasteBlocks(bid, cd) {
    const blocks = readBlocks(cd.getData('text/plain'));
    const n = this.card;
    if (!blocks || !blocks.length || !n) return false;
    let i = n.blocks.findIndex((b) => b.id === bid);
    const cur = n.blocks[i];
    const bc = this.host.querySelector(`.tf-block[data-bid="${bid}"] .tf-bc`);
    if (bc) { const c = this.blockCtx(bc); if (c) this.sync(c); }
    if (cur && cur.type === 'text' && !textLen(cur.html) && !/tf-mention/.test(cur.html || '')) n.blocks.splice(i--, 1);
    n.blocks.splice(i + 1, 0, ...blocks);
    const last = blocks[blocks.length - 1];
    if (isVoid(last.type)) n.blocks.splice(i + 1 + blocks.length, 0, nb('text'));
    this.refresh();
    const after = isVoid(last.type) ? n.blocks[i + 1 + blocks.length] : last;
    this.focusBlock(after.id, 'end');
    this.app.onCardResize(n.id);
    return true;
  }

  // ------------------------------------------------ menus « / » et « @ »
  openTrigger(kind, c, anchor) {
    const meta = { bid: c.b.id, anchor };
    this.trigger = { kind, bid: c.b.id, anchor };
    if (kind === 'slash') {
      this.app.menu.open(SLASH_ITEMS, caretRect(), (it, m) => { this.trigger = null; this.applySlash(it, m); },
        { header: 'Tape pour filtrer', meta });
    } else {
      this.app.menu.open(this.mentionItems(), caretRect(), (it, m) => { this.trigger = null; this.insertMention(it, m); },
        { header: 'Lier une carte : tape son titre', empty: 'Aucune carte trouvée', meta });
    }
  }

  updateTrigger() {
    const tr = this.trigger;
    const bc = this.host && this.host.querySelector(`.tf-block[data-bid="${tr.bid}"] .tf-bc`);
    if (!bc || document.activeElement !== bc || !this.app.menu.isOpen) return this.closeTrigger();
    const off = caretOffset(bc), txt = bc.textContent;
    const ch = tr.kind === 'slash' ? '/' : '@';
    if (off <= tr.anchor || txt.charAt(tr.anchor) !== ch) return this.closeTrigger();
    const f = txt.slice(tr.anchor + 1, off);
    if (f.length > 40 || /\s\s/.test(f) || (tr.kind === 'slash' && f.length > 24)) return this.closeTrigger();
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
      hint: h.board.id === app.boardId ? '' : app.boardLabel(h.board),
      icon: h.node.childBoardId ? '▦' : '@'
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
    document.execCommand('insertHTML', false,
      `<span class="tf-mention" data-card="${item.id}" contenteditable="false">${esc(item.label)}</span>&nbsp;`);
    this.sync({ bc, b, isBody: false });
  }

  /** Ouvre le menu de liens « @ » depuis la position du curseur (bouton ou menu « / »). */
  startMention(info) {
    const { b, bc } = this._consumeTrigger(info);
    if (!bc) return;
    const c = this.blockCtx(bc);
    bc.focus();
    document.execCommand('insertText', false, '@');
    this.sync(c);
    this.openTrigger('mention', c, caretOffset(bc) - 1);
    void b;
  }

  applySlash(item, info) {
    if (item.id === 'mention') return this.startMention(info);
    const { n, b, bc } = this._consumeTrigger(info);
    if (!b) return;
    const idx = n.blocks.indexOf(b);
    if (item.id === 'date' || item.id === 'now') {
      const d = new Date();
      const txt = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) +
        (item.id === 'now' ? ', ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '');
      if (bc) { bc.focus(); document.execCommand('insertText', false, txt); this.sync({ bc, b, isBody: false }); }
      return;
    }
    const empty = !isVoid(b.type) && !blockText(b).trim() && !/tf-mention/.test(b.html || '');
    if (item.id === 'image') {
      this.app.pickImage((src) => this.addImage({ bid: b.id }, src, true));
      return;
    }
    if (item.id === 'divider') {
      const dv = nb('divider');
      if (empty && b.type === 'text') n.blocks.splice(idx, 1, dv); else n.blocks.splice(idx + 1, 0, dv);
      const k = n.blocks.indexOf(dv);
      let nx = n.blocks[k + 1];
      if (!nx || isVoid(nx.type)) { nx = nb('text'); n.blocks.splice(k + 1, 0, nx); }
      this.refresh(); this.focusBlock(nx.id, 'start');
      return;
    }
    let target = b;
    if (empty) convertBlock(b, item.id);
    else {
      target = nb(item.id, '', item.id === 'code' ? { text: '' } : null);
      n.blocks.splice(idx + 1, 0, target);
    }
    if (item.id === 'toggle') { target.open = true; if (target.body == null) target.body = ''; }
    this.refresh();
    this.focusBlock(target.id, 'start');
  }

  /** Ajoute une image après le bloc info.bid (ou à sa place s'il est vide). */
  addImage(info, src, replaceIfEmpty) {
    this.app.addImageBlock(this.cardId, info.bid, src, replaceIfEmpty);
  }

  // ------------------------------------------------ barre de mise en forme
  command(cmd) {
    if (cmd === 'done') return this.app.focus.isOpen ? this.app.focus.close() : this.stop();
    const n = this.card;
    if (!n) return;
    const s = window.getSelection();
    let c = s.rangeCount ? this.blockCtx(s.anchorNode) : null;
    if (!c) {
      let last = n.blocks[n.blocks.length - 1];
      if (!last || isVoid(last.type)) { last = nb('text'); n.blocks.push(last); this.refresh(); }
      this.focusBlock(last.id, 'end');
      c = this.blockCtx(window.getSelection().anchorNode);
      if (!c) return;
    }
    const info = { bid: c.b.id, anchor: null };
    switch (cmd) {
      case 'bold': document.execCommand('bold'); break;
      case 'italic': document.execCommand('italic'); break;
      case 'strike': document.execCommand('strikeThrough'); break;
      case 'icode':
        if (c.b.type !== 'code') document.execCommand('insertHTML', false, '<code>' + esc(s.toString() || 'code') + '</code>');
        break;
      case 'link': this._link(c); return;
      case 'mention': this.startMention(info); return;
      case 'insert': {
        const r = this.app.toolbars.buttonRect('insert');
        this.app.menu.open(SLASH_ITEMS, r, (it) => this.applySlash(it, info));
        return;
      }
      case 'image': this.applySlash({ id: 'image' }, info); return;
    }
    if (c.bc.isConnected) this.sync(c);
    this.app.onCardResize(this.cardId);
  }

  async _link(c) {
    const s = window.getSelection();
    const saved = s.rangeCount ? s.getRangeAt(0).cloneRange() : null;
    const url = await this.app.dialogs.prompt({ title: 'Ajouter un lien', placeholder: 'https://…', value: 'https://' });
    if (!url || url === 'https://' || !c.bc.isConnected) return;
    c.bc.focus();
    if (saved) { s.removeAllRanges(); s.addRange(saved); }
    if (!saved || saved.collapsed) document.execCommand('insertHTML', false, `<a href="${esc(url)}">${esc(url)}</a>`);
    else document.execCommand('createLink', false, url);
    this.sync(c);
  }

  // ------------------------------------------------ menu d'un bloc (clic sur la poignée)
  openBlockMenu(cardId, bid, rect) {
    const app = this.app, n = app.node(cardId);
    const b = n && n.blocks.find((x) => x.id === bid);
    if (!b) return;
    const items = [];
    if (!isVoid(b.type)) {
      items.push({ group: 'Transformer en' });
      for (const t of TEXT_TYPES) items.push({ id: 'to:' + t, label: BLOCK_TYPES[t].label, icon: BLOCK_TYPES[t].icon, on: b.type === t });
      items.push({ group: 'Bloc' });
    }
    items.push({ id: 'dup', label: 'Dupliquer', icon: '⧉' }, { id: 'del', label: 'Supprimer', icon: '✕', danger: true });
    app.menu.open(items, rect, (it) => {
      const nn = app.node(cardId);
      const i = nn ? nn.blocks.findIndex((x) => x.id === bid) : -1;
      if (i < 0) return;
      const bb = nn.blocks[i];
      if (it.id === 'dup') { const cb = clone(bb); cb.id = uid('k'); nn.blocks.splice(i + 1, 0, cb); }
      else if (it.id === 'del') nn.blocks.splice(i, 1);
      else { convertBlock(bb, it.id.slice(3)); if (bb.type === 'toggle') bb.open = true; }
      app.refreshCard(cardId);
      app.commit();
      if (this.cardId === cardId && it.id !== 'del') this.focusBlock(bb.id, 'end');
    });
  }
}
