const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1400, height: 860 } });
  const errs = [];
  p.on('pageerror', (e) => errs.push('pageerror: ' + e.stack));
  p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errs.push(m.type() + ': ' + m.text()); });
  await p.goto('file://' + path.resolve(__dirname, '../index.html'));
  await p.waitForTimeout(500);
  await p.screenshot({ path: path.resolve(__dirname, 'out-smoke.png') });
  console.log(await p.evaluate(() => ({ cards: document.querySelectorAll('.tf-card').length, edges: document.querySelectorAll('.tf-edge').length })));
  console.log(errs.join('\n') || 'no errors');
  await b.close();
})();
