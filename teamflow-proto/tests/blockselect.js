// Sélection de plusieurs blocs : glisser, Maj+flèches, copier/coller, supprimer, déplacer le groupe
const { chromium } = require('playwright');
const path = require('path');
const URL = 'file://' + path.resolve(__dirname, '../index.html');
let fails = 0;
const ok = (c, l) => { console.log((c ? '  ok   ' : '  FAIL ') + l); if (!c) fails++; };

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 860 } });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write']);
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push(e.stack));
  await p.goto(URL); await p.waitForTimeout(400);
  const id = (t) => p.evaluate((t) => teamflow.board.nodes.find((n) => n.title === t).id, t);
  const blocksOf = (cid) => p.evaluate((cid) => teamflow.node(cid).blocks.map((b) => b.type + ':' + (b.html || b.text || '').replace(/<[^>]+>/g, '').slice(0, 18)), cid);
  const sel = () => p.evaluate(() => teamflow.editor.bsel.ids.length);
  const rac = await id('Raccourcis'), vis = await id('Vision');
  const blk = (cid, i) => p.locator(`.tf-world .tf-card[data-id="${cid}"] .tf-card-body > .tf-block`).nth(i);

  // édition puis glisser du 1er au 3e bloc
  let r0 = await blk(rac, 0).boundingBox();
  await p.mouse.dblclick(r0.x + 60, r0.y + r0.height / 2); await p.waitForTimeout(150);
  r0 = await blk(rac, 0).boundingBox();
  const r2 = await blk(rac, 2).boundingBox();
  await p.mouse.move(r0.x + 60, r0.y + r0.height / 2); await p.mouse.down();
  await p.mouse.move(r2.x + 80, r2.y + r2.height / 2, { steps: 8 }); await p.mouse.up();
  ok(await sel() === 3, 'glisser sur 3 blocs : 3 blocs sélectionnés (' + await sel() + ')');
  await p.screenshot({ path: path.resolve(__dirname, 'out-bsel.png') });

  // Maj+↓ étend
  await p.keyboard.press('Shift+ArrowDown');
  ok(await sel() === 4, 'Maj+↓ étend à 4 blocs');
  await p.keyboard.press('Shift+ArrowUp');
  ok(await sel() === 3, 'Maj+↑ revient à 3');

  // copier puis coller dans une autre carte
  await p.keyboard.press('Control+c'); await p.waitForTimeout(100);
  const clip = await p.evaluate(() => navigator.clipboard.readText());
  console.log('    presse-papiers :', JSON.stringify(clip.slice(0, 60)));
  ok(clip.split('\n').length === 3, 'texte copié : 3 lignes');
  await p.keyboard.press('Escape');
  ok(await sel() === 0 && await p.evaluate(() => teamflow.editor.active), 'Échap : sélection annulée, édition continue');
  await p.keyboard.press('Escape'); await p.waitForTimeout(100);
  const before = (await blocksOf(vis)).length;
  const rv = await blk(vis, 0).boundingBox();
  await p.mouse.dblclick(rv.x + 40, rv.y + rv.height / 2); await p.waitForTimeout(150);
  await p.keyboard.press('Control+End'); await p.keyboard.press('Enter');
  await p.keyboard.press('Control+v'); await p.waitForTimeout(150);
  const after = await blocksOf(vis);
  console.log('    Vision :', after);
  ok(after.length === before + 3 && after.filter((x) => x.startsWith('bullet')).length >= 3, 'collage : 3 blocs liste ajoutés à Vision');

  // depuis le dernier bloc collé (curseur à sa fin) : Maj+↑ ×2 puis Suppr
  await p.keyboard.press('Shift+ArrowUp'); await p.keyboard.press('Shift+ArrowUp');
  ok(await sel() === 3, 'Maj+↑ ×2 depuis la fin : 3 blocs');
  await p.keyboard.press('Delete'); await p.waitForTimeout(100);
  ok((await blocksOf(vis)).length === before, 'Suppr : blocs supprimés');
  ok(await p.evaluate(() => teamflow.node(teamflow.selection.first()) && !!teamflow.editor.active), 'la carte n\'a pas été supprimée');
  await p.keyboard.press('Control+a'); await p.keyboard.press('Control+a');
  ok(await sel() === before, 'Ctrl+A ×2 : tous les blocs');
  await p.keyboard.press('Escape');
  await p.keyboard.press('Escape'); await p.waitForTimeout(100);
  ok(!(await blocksOf(vis)).some((x) => x === 'text:'), 'à la sortie, le bloc vide est retiré');

  // déplacer le groupe par la poignée vers une autre carte
  r0 = await blk(rac, 0).boundingBox();
  await p.mouse.dblclick(r0.x + 60, r0.y + r0.height / 2); await p.waitForTimeout(150);
  r0 = await blk(rac, 0).boundingBox();
  const r1 = await blk(rac, 1).boundingBox();
  await p.mouse.move(r0.x + 60, r0.y + r0.height / 2); await p.mouse.down();
  await p.mouse.move(r1.x + 80, r1.y + r1.height / 2, { steps: 6 }); await p.mouse.up();
  ok(await sel() === 2, '2 blocs sélectionnés');
  const nRac = (await blocksOf(rac)).length;
  await blk(rac, 0).hover();
  const g = await p.locator(`.tf-world .tf-card[data-id="${rac}"] .tf-card-body > .tf-block`).nth(0).locator('.tf-grip').boundingBox();
  const tv = await p.locator(`.tf-world .tf-card[data-id="${vis}"]`).boundingBox();
  await p.mouse.move(g.x + 8, g.y + 8); await p.mouse.down();
  await p.mouse.move(tv.x + 100, tv.y + tv.height - 10, { steps: 12 }); await p.mouse.up(); await p.waitForTimeout(150);
  ok((await blocksOf(rac)).length === nRac - 2 && (await blocksOf(vis)).length === before + 2, 'poignée : le groupe de 2 blocs part dans Vision');

  // un clic simple dans un bloc reste une édition de texte normale
  await p.keyboard.press('Escape');
  console.log(errs.length ? 'ERREURS:\n' + errs.join('\n') : '  aucune erreur JS');
  console.log(fails ? fails + ' échec(s)' : 'tout est vert');
  await b.close();
  process.exit(fails || errs.length ? 1 : 0);
})();
