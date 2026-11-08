// Comportements déjà livrés en v0.1–v0.3 : menu /, Markdown, glisser un bloc, flèches, sous-whiteboard, zoom, thème
const { chromium } = require('playwright');
const path = require('path');
const URL = 'file://' + path.resolve(__dirname, '../index.html');
let fails = 0;
const ok = (c, l) => { console.log((c ? '  ok   ' : '  FAIL ') + l); if (!c) fails++; };

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 860 } });
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.stack));
  p.on('dialog', (d) => { errs.push('NATIVE DIALOG'); d.dismiss(); });
  await p.goto(URL); await p.waitForTimeout(400);
  const nodes = () => p.evaluate(() => teamflow.board.nodes.length);

  // nouvelle carte + menu / + Markdown
  const n0 = await nodes();
  await p.mouse.dblclick(150, 560); await p.waitForTimeout(150);
  await p.keyboard.type('/');
  ok(await p.locator('.tf-menu').isVisible(), 'menu « / » ouvert');
  await p.keyboard.type('titre 1'); await p.keyboard.press('Enter');
  await p.keyboard.type('Grand titre'); await p.keyboard.press('Enter');
  await p.keyboard.type('- puce'); await p.keyboard.press('Enter');
  await p.keyboard.type('[] tâche');
  await p.keyboard.press('Escape'); await p.waitForTimeout(100);
  const nc = await p.evaluate(() => { const n = teamflow.board.nodes[teamflow.board.nodes.length - 1]; return n.blocks.map((b) => b.type); });
  console.log('    blocs :', nc);
  ok(await nodes() === n0 + 1 && nc.join() === 'h1,bullet,todo', 'carte créée avec titre 1, puce, tâche');

  // glisser un bloc sur le canevas = nouvelle carte
  const last = await p.evaluate(() => teamflow.board.nodes[teamflow.board.nodes.length - 1].id);
  const be = p.locator(`.tf-world .tf-card[data-id="${last}"] .tf-card-body > .tf-block`).nth(2);
  await be.hover();
  const g = await be.locator('.tf-grip').boundingBox();
  await p.mouse.move(g.x + 8, g.y + 8); await p.mouse.down();
  await p.mouse.move(250, 800, { steps: 10 }); await p.mouse.up(); await p.waitForTimeout(100);
  ok(await nodes() === n0 + 2, 'bloc glissé sur le canevas : nouvelle carte');

  // flèche par la poignée de bord
  const e0 = await p.evaluate(() => teamflow.board.edges.length);
  const src = p.locator(`.tf-world .tf-card[data-id="${last}"]`);
  await src.hover();
  const h = await src.locator('.tf-handle[data-side="r"]').boundingBox();
  const tgt = await p.locator('.tf-world .tf-card', { hasText: 'Modes de navigation' }).boundingBox();
  await p.mouse.move(h.x + 7, h.y + 7); await p.mouse.down();
  await p.mouse.move(tgt.x + 50, tgt.y + 40, { steps: 10 }); await p.mouse.up(); await p.waitForTimeout(100);
  ok(await p.evaluate(() => teamflow.board.edges.length) === e0 + 1, 'flèche créée entre deux cartes');

  // sous-whiteboard
  await p.locator('.tf-open').first().click(); await p.waitForTimeout(300);
  ok(await p.locator('.tf-crumb').count() === 2, 'sous-whiteboard ouvert (fil d\'Ariane)');
  await p.locator('.tf-crumb').first().click(); await p.waitForTimeout(200);

  // zoom Ctrl+molette : un cran reste doux
  const z0 = await p.evaluate(() => teamflow.viewport.view.z);
  await p.mouse.move(700, 400); await p.keyboard.down('Control'); await p.mouse.wheel(0, -100); await p.keyboard.up('Control'); await p.waitForTimeout(100);
  const z1 = await p.evaluate(() => teamflow.viewport.view.z);
  ok(z1 > z0 && z1 / z0 < 1.15, `zoom doux (${(z1 / z0).toFixed(3)})`);

  // thème
  const th = await p.evaluate(() => teamflow.root.dataset.theme);
  await p.click('[data-act="toggle-theme"]');
  ok(await p.evaluate(() => teamflow.root.dataset.theme) !== th, 'bascule clair / sombre');
  await p.screenshot({ path: path.resolve(__dirname, 'out-dark.png') });

  // annuler / rétablir
  const n1 = await nodes();
  await p.keyboard.press('Control+z'); await p.keyboard.press('Control+z');
  ok(await nodes() <= n1, 'annuler');
  await p.keyboard.press('Control+y'); await p.keyboard.press('Control+y');
  ok(await nodes() === n1, 'rétablir');

  console.log(errs.length ? 'ERREURS:\n' + errs.join('\n') : '  aucune erreur JS');
  console.log(fails ? fails + ' échec(s)' : 'tout est vert');
  await b.close();
  process.exit(fails || errs.length ? 1 : 0);
})();
