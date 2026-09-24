/**
 * Übertragungsgröße einer Seite (Ziel Startseite < 800 KB) und Anzahl
 * der Anfragen. Mit Kompression, wie sie Caddy ausliefert (gzip/zstd),
 * ist der Wert kleiner – gemessen wird hier ohne Kompression (PHP-Server).
 *
 *   BASE_URL=http://localhost:8000 NODE_PATH="$(npm root -g)" node tests/browser/weight.cjs [/pfad]
 */
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://localhost:8000';

(async () => {
  const browser = await chromium.launch();
  for (const width of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const sizes = [];
    page.on('response', async (r) => {
      try { sizes.push({ url: r.url(), bytes: (await r.body()).length }); } catch (e) {}
    });
    await page.goto(base + (process.argv[2] || '/'), { waitUntil: 'networkidle' });
    const total = sizes.reduce((s, x) => s + x.bytes, 0);
    const js = sizes.filter((x) => x.url.includes('.js')).reduce((s, x) => s + x.bytes, 0);
    console.log(`${width}px: ${sizes.length} Anfragen, ${(total / 1024).toFixed(0)} KB (JS ${(js / 1024).toFixed(1)} KB)`);
    if (total > 800 * 1024) process.exitCode = 1;
    await page.close();
  }
  await browser.close();
})();
