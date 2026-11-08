// Les données enregistrées par la v0.3 (format 2) s'ouvrent dans la v0.4 (format 3)
const { chromium } = require('playwright');
const path = require('path');
const OLD = process.argv[2]; // chemin de l'ancienne index.html (v0.3)
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 860 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.stack));
  await p.goto('file://' + OLD); await p.waitForTimeout(400);
  // ajoute une image inline façon v0.3 pour vérifier l'extraction en « assets »
  await p.evaluate(() => { const n = teamflow.board.nodes.find((n) => n.title === 'Vision'); n.blocks.push({ id: 'kimg', type: 'image', html: '', src: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' }); teamflow.commit(); });
  await p.waitForTimeout(900);
  const raw = await p.evaluate(() => localStorage.getItem('teamflow-demo'));
  console.log('  ancien format :', JSON.parse(raw).version);
  await p.goto('file://' + path.resolve(__dirname, '../index.html')); await p.waitForTimeout(500);
  const r = await p.evaluate(() => {
    const d = teamflow.store.toJSON(); const v = teamflow.board.nodes.find((n) => n.title === 'Vision');
    const img = v.blocks.find((b) => b.type === 'image');
    return { version: d.version, assets: Object.keys(d.assets).length, imgAsset: !!(img && img.asset && !img.src), rendered: !!document.querySelector('.tf-b-image img[src^="data:"]') };
  });
  console.log('  après ouverture :', r);
  const ok = r.version === 3 && r.assets === 1 && r.imgAsset && r.rendered && !errs.length;
  console.log(errs.join('\n') || '  aucune erreur JS', ok ? '\ntout est vert' : '\nÉCHEC');
  await b.close(); process.exit(ok ? 0 : 1);
})();
