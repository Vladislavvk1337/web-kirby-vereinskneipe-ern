/**
 * Browser-Prüfung mit Playwright/Chromium:
 *  - kein horizontales Scrollen bei 375/390/768/1024/1280/1920 px
 *  - keine Konsolenfehler, keine fehlgeschlagenen Anfragen
 *  - Tastatur: Sprunglink ist das erste fokussierbare Element
 *  - 200 % Zoom (640 px CSS-Breite bei 1280er Fenster) ohne Überlauf
 *  - Bilder haben ein alt-Attribut
 *  - Screenshots nach tests/screenshots/ (optional)
 *
 *   BASE_URL=http://localhost:8000 NODE_PATH="$(npm root -g)" node tests/browser/check.cjs [--screenshots]
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://localhost:8000';
const shots = process.argv.includes('--screenshots');
const outDir = path.resolve(__dirname, '../screenshots');
const pages = (process.env.PAGES || [
  '/', '/termine', '/termine?jahr=2026&monat=10', '/termine/kneipenabend-mit-dem-beispiel-thekenteam',
  '/termine/beispieltermin-noch-frei-16-10', '/termin-anfragen', '/thekenteams',
  '/thekenteams/beispiel-thekenteam', '/ueber-uns', '/mitmachen', '/aktuelles',
  '/aktuelles/rueckblick-sommerabend', '/kontakt', '/impressum', '/datenschutz',
  '/barrierefreiheit', '/bausteine', '/gibt-es-nicht',
].join(',')).split(',');
const widths = [375, 390, 768, 1024, 1280, 1920];

(async () => {
  const browser = await chromium.launch();
  const failures = [];
  if (shots) fs.mkdirSync(outDir, { recursive: true });

  for (const width of widths) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('console', (msg) => msg.type() === 'error' && errors.push(msg.text()));
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('requestfailed', (req) => errors.push('Anfrage fehlgeschlagen: ' + req.url()));

    for (const url of pages) {
      errors.length = 0;
      const response = await page.goto(base + url, { waitUntil: 'networkidle' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      const imgsWithoutAlt = await page.$$eval('img:not([alt])', (imgs) => imgs.map((i) => i.src));
      const status = response.status();

      if (overflow > 0) failures.push(`${width}px ${url}: horizontaler Überlauf ${overflow}px`);
      if (imgsWithoutAlt.length) failures.push(`${width}px ${url}: Bild ohne alt: ${imgsWithoutAlt.join(', ')}`);
      // 404-Seite erzeugt erwartungsgemäß einen Konsolenhinweis auf den Statuscode
      const relevant = errors.filter((e) => !(status === 404 && /404/.test(e)));
      if (relevant.length) failures.push(`${width}px ${url}: ${relevant.join(' | ')}`);

      if (shots && (width === 390 || width === 1280)) {
        const name = (url === '/' ? 'start' : url.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')) + `-${width}.png`;
        await page.screenshot({ path: path.join(outDir, name), fullPage: true });
      }
    }

    await context.close();
  }

  // Tastatur: erster Tab-Stopp ist der Sprunglink, danach Fokus sichtbar
  const context = await browser.newContext({ viewport: { width: 390, height: 900 } });
  const page = await context.newPage();
  await page.goto(base + '/');
  await page.keyboard.press('Tab');
  const first = await page.evaluate(() => ({ cls: document.activeElement.className, text: document.activeElement.textContent.trim() }));
  if (!/skip-link/.test(first.cls)) failures.push('Erster Tab-Stopp ist nicht der Sprunglink: ' + JSON.stringify(first));
  const outline = await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
  if (outline === 'none') failures.push('Sprunglink hat keinen sichtbaren Fokus');

  // Mobiles Menü: Knopf sichtbar, klappt auf, Escape schließt
  const toggle = page.locator('.site-nav__toggle');
  if (!(await toggle.isVisible())) failures.push('Menüknopf auf 390px nicht sichtbar');
  await toggle.click();
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') failures.push('Menü öffnet nicht');
  if (!(await page.locator('#hauptmenue').isVisible())) failures.push('Menüliste nach Öffnen unsichtbar');
  await page.keyboard.press('Escape');
  if ((await toggle.getAttribute('aria-expanded')) !== 'false') failures.push('Escape schließt das Menü nicht');

  // Anfrageformular komplett per Tastatur erreichbar: Absende-Knopf per Tab
  await page.goto(base + '/termin-anfragen');
  let reached = false;
  for (let i = 0; i < 60 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => document.activeElement?.type === 'submit');
  }
  if (!reached) failures.push('Absende-Knopf des Formulars per Tab nicht erreichbar');
  await context.close();

  // 200 % Zoom: 1280er Fenster mit doppelter Skalierung entspricht 640 CSS-Pixeln
  const zoom = await browser.newContext({ viewport: { width: 640, height: 450 }, deviceScaleFactor: 2 });
  const zp = await zoom.newPage();
  for (const url of ['/', '/termine', '/termin-anfragen', '/termine/kneipenabend-mit-dem-beispiel-thekenteam']) {
    await zp.goto(base + url);
    const overflow = await zp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (overflow > 0) failures.push(`200 % Zoom ${url}: horizontaler Überlauf ${overflow}px`);
  }
  await zoom.close();

  // Bewegung reduzieren: Übergänge praktisch abgeschaltet
  const reduced = await browser.newContext({ reducedMotion: 'reduce' });
  const rp = await reduced.newPage();
  await rp.goto(base + '/');
  const duration = await rp.evaluate(() => getComputedStyle(document.querySelector('.button')).transitionDuration);
  if (!/^0\.00001s|^1e-05s|^0s/.test(duration.split(',')[0])) failures.push('prefers-reduced-motion greift nicht: ' + duration);
  await reduced.close();

  await browser.close();

  if (failures.length) {
    console.error('✘ ' + failures.length + ' Probleme:\n  ' + failures.join('\n  '));
    process.exit(1);
  }

  console.log(`✔ Browser-Prüfung: ${pages.length} Seiten × ${widths.length} Breiten, Tastatur, Menü, 200 % Zoom, reduzierte Bewegung`);
})();
