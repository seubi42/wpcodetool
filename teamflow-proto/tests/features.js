// Parcours fonctionnel de la v0.4 (Playwright, sans framework de test)
const { chromium } = require('playwright');
const path = require('path');
const URL = 'file://' + path.resolve(__dirname, '../index.html');
const shot = (n) => path.resolve(__dirname, 'out-' + n + '.png');
let fails = 0;
const ok = (cond, label) => { console.log((cond ? '  ok   ' : '  FAIL ') + label); if (!cond) fails++; };

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 860 } });
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.stack));
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('dialog', (d) => { errs.push('NATIVE DIALOG: ' + d.message()); d.dismiss(); });
  await p.goto(URL); await p.waitForTimeout(400);
  const T = (t) => p.evaluate((t) => teamflow.board.nodes.find((n) => n.title === t).id, t);
  const cardSel = (id) => `.tf-world .tf-card[data-id="${id}"]`;
  const center = async (sel) => { const r = await p.locator(sel).first().boundingBox(); return [r.x + r.width / 2, r.y + r.height / 2, r]; };

  // 1. édition d'une carte existante + barre au-dessus de la carte
  const vision = await T('Vision');
  let [x, y, r] = await center(cardSel(vision) + ' .tf-card-body');
  await p.mouse.dblclick(x, y); await p.waitForTimeout(150);
  ok(await p.locator(cardSel(vision)).evaluate((e) => e.classList.contains('tf-editing')), 'double-clic = édition');
  const bar = await p.locator('.tf-rtbar').boundingBox();
  ok(bar.y + bar.height <= r.y + 2 && Math.abs(bar.x + bar.width / 2 - (r.x + r.width / 2)) < 200, 'barre de mise en forme au-dessus de la carte');

  // 2. lien @ vers une carte d'un autre whiteboard
  await p.keyboard.press('Control+End');
  await p.keyboard.press('Enter');
  await p.keyboard.type('Voir @API');
  await p.waitForTimeout(150);
  const items = await p.locator('.tf-menu .tf-menu-item').allTextContents();
  console.log('    menu @ :', items.slice(0, 4));
  await p.keyboard.press('Enter'); await p.waitForTimeout(100);
  ok(await p.locator(cardSel(vision) + ' .tf-mention').count() === 1, 'mention insérée');
  await p.keyboard.press('Escape'); await p.waitForTimeout(150);
  const target = await p.evaluate((id) => {
    const n = teamflow.node(id); const h = JSON.stringify(n.blocks); return /tf-mention/.test(h) && h.match(/data-card=\\?"([^"\\]+)/)[1];
  }, vision);
  ok(!!target, 'mention enregistrée dans le modèle');
  const before = await p.evaluate(() => teamflow.boardId);
  await p.locator(cardSel(vision) + ' .tf-mention').click(); await p.waitForTimeout(500);
  const after = await p.evaluate(() => [teamflow.boardId, [...teamflow.selection.ids]]);
  ok(after[0] !== before && after[1][0] === target, 'clic sur le lien : change de whiteboard et sélectionne la carte');
  await p.screenshot({ path: shot('mention-jump') });
  await p.evaluate(() => teamflow.goUp()); await p.waitForTimeout(200);

  // 3. carte sans titre
  await p.mouse.dblclick(200, 700); await p.waitForTimeout(150);
  await p.keyboard.type('Juste un texte, sans titre');
  await p.keyboard.press('Escape'); await p.waitForTimeout(150);
  const nt = await p.evaluate(() => { const n = teamflow.board.nodes.find((n) => n.blocks && JSON.stringify(n.blocks).includes('sans titre')); return n && { title: n.title, id: n.id }; });
  ok(nt && !nt.title, 'nouvelle carte sans titre');
  ok(nt && !(await p.locator(cardSel(nt.id) + ' .tf-card-head').isVisible()), 'en-tête masqué sans titre');

  // 4. replier / déplier (persisté)
  const dq = await T('Démonstrateur JS');
  await p.locator(cardSel(dq)).hover();
  await p.locator(cardSel(dq) + ' [data-card-act="collapse"]').click(); await p.waitForTimeout(100);
  ok(await p.locator(cardSel(dq)).evaluate((e) => e.classList.contains('tf-collapsed') && e.offsetHeight < 60), 'carte repliée');
  await p.waitForTimeout(800);
  await p.reload(); await p.waitForTimeout(400);
  ok(await p.locator(cardSel(dq)).evaluate((e) => e.classList.contains('tf-collapsed')), 'état replié conservé après rechargement');
  await p.screenshot({ path: shot('collapsed') });

  // 5. plein écran
  await p.locator(cardSel(dq)).hover();
  await p.locator(cardSel(dq) + ' [data-card-act="focus"]').click(); await p.waitForTimeout(200);
  ok(await p.locator('.tf-focus').isVisible(), 'vue plein écran ouverte');
  await p.keyboard.press('End'); await p.keyboard.type(' (édité en plein écran)');
  await p.screenshot({ path: shot('focus') });
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  ok(await p.locator('.tf-focus').count() === 0, 'Échap : retour au schéma');
  ok(await p.evaluate((id) => JSON.stringify(teamflow.node(id).blocks).includes('édité en plein écran'), dq), 'modification en plein écran enregistrée');

  // 6. flèche en pointillé
  await p.locator('.tf-edge-label span', { hasText: 'à tranch' }).click(); await p.waitForTimeout(100);
  await p.locator('[data-sel="style-dashed"]').click(); await p.waitForTimeout(100);
  ok(await p.evaluate(() => document.querySelectorAll('.tf-edge-line.tf-dashed').length === 1), 'flèche pointillée');
  const sb = await p.locator('.tf-selbar').boundingBox();
  ok(sb.y > 50, 'barre de sélection placée près de la flèche');
  await p.screenshot({ path: shot('dashed') });

  // 7. confirmation maison avant suppression
  const pist = await T('Pistes');
  await p.locator(cardSel(pist)).click(); await p.waitForTimeout(100);
  await p.keyboard.press('Delete'); await p.waitForTimeout(100);
  ok(await p.locator('.tf-modal').isVisible() || await p.evaluate((id) => !teamflow.node(id), pist), 'suppression : dialogue maison (ou suppression directe si carte simple)');
  if (await p.locator('.tf-modal').count()) { await p.screenshot({ path: shot('modal') }); await p.keyboard.press('Escape'); }
  // whiteboard : toujours confirmation
  const wp = await T('Intégration WordPress');
  await p.locator(cardSel(wp)).click(); await p.keyboard.press('Delete'); await p.waitForTimeout(100);
  ok(await p.locator('.tf-modal').isVisible(), 'suppression d\'un whiteboard : dialogue maison');
  await p.screenshot({ path: shot('modal') });
  await p.locator('.tf-modal-cancel').click(); await p.waitForTimeout(100);
  ok(await p.evaluate((id) => !!teamflow.node(id), wp), 'Annuler garde la carte');

  // 8. déplacement d'une carte : la flèche suit
  const d0 = await p.evaluate(() => document.querySelector('.tf-edge-line').getAttribute('d'));
  [x, y] = await center(cardSel(vision) + ' .tf-card-head');
  await p.mouse.move(x, y); await p.mouse.down(); await p.mouse.move(x + 60, y + 80, { steps: 5 }); await p.mouse.up();
  const d1 = await p.evaluate(() => document.querySelector('.tf-edge-line').getAttribute('d'));
  ok(d0 !== d1, 'la flèche suit la carte déplacée');
  await p.keyboard.press('Control+z'); await p.waitForTimeout(100);
  ok(await p.evaluate(() => document.querySelector('.tf-edge-line').getAttribute('d')) === d0, 'annuler le déplacement');

  console.log(errs.length ? 'ERREURS:\n' + errs.join('\n') : '  aucune erreur JS');
  console.log(fails ? fails + ' échec(s)' : 'tout est vert');
  await b.close();
  process.exit(fails || errs.length ? 1 : 0);
})();
