/** Raccourcis clavier globaux (hors saisie de texte). */
export function bindKeyboard(app) {
  window.addEventListener('keydown', (e) => {
    if (!app.isActive() || app.dialogs.isOpen) return;
    if (app.menu.key(e)) return;
    const mod = e.ctrlKey || e.metaKey;
    if (app.editor.active) {
      if (e.key === 'Escape' || (mod && e.key === 'Enter')) {
        e.preventDefault();
        if (app.focus.isOpen) app.focus.close(); else app.editor.stop();
      }
      return;
    }
    if (app.focus.isOpen) { if (e.key === 'Escape') app.focus.close(); return; }
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
    if (e.code === 'Space') {
      e.preventDefault();
      if (!app.spaceDown) { app.spaceDown = true; app.root.classList.add('tf-space'); }
      return;
    }
    const k = e.key.toLowerCase();
    if (mod && k === 'z' && !e.shiftKey) { e.preventDefault(); app.undo(); return; }
    if (mod && (k === 'y' || (k === 'z' && e.shiftKey))) { e.preventDefault(); app.redo(); return; }
    if (mod && k === 'd') { e.preventDefault(); app.duplicateSelection(); return; }
    if (mod && k === 'a') { e.preventDefault(); app.select(app.board.nodes.map((n) => n.id)); return; }
    if (mod) return;
    if (e.shiftKey && e.code === 'Digit1') { e.preventDefault(); app.fit(); return; }
    switch (e.key) {
      case 'Delete': case 'Backspace': e.preventDefault(); app.deleteSelection(); break;
      case 'Escape': if (!app.selection.empty) app.clearSelection(); else app.goUp(); break;
      case 'Enter':
        if (app.selection.size === 1) {
          e.preventDefault();
          const n = app.node(app.selection.first());
          if (n && n.type === 'card') app.editor.start(n.id); else if (n) app.editSectionTitle(n.id);
        }
        break;
      case '+': case '=': app.viewport.zoomCenter(1.25); break;
      case '-': case '_': app.viewport.zoomCenter(0.8); break;
      case '0': app.viewport.zoomCenter(1 / app.viewport.view.z); break;
    }
  });
  window.addEventListener('keyup', (e) => {
    if (e.code === 'Space') { app.spaceDown = false; app.root.classList.remove('tf-space'); }
  });
  window.addEventListener('blur', () => { app.spaceDown = false; app.root.classList.remove('tf-space'); });
}
