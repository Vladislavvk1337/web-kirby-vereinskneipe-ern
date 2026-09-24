/**
 * Panel-Prüfung aus Sicht von Administration und Moderation.
 * Meldet Fehler im Panel (Konsole, Fehlerdialoge) und prüft, was die
 * Moderation sehen darf.
 *
 *   ADMIN=admin@example.org:passwort MOD=moderation@example.org:passwort \
 *   BASE_URL=http://localhost:8000 NODE_PATH="$(npm root -g)" node tests/browser/panel.cjs [--screenshots]
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://localhost:8000';
const shots = process.argv.includes('--screenshots');
const outDir = path.resolve(__dirname, '../screenshots');
const accounts = { admin: process.env.ADMIN, moderator: process.env.MOD };
const failures = [];

const views = [
  ['dashboard', '/panel/site'],
  ['termine', '/panel/pages/termine'],
  ['termin', '/panel/pages/termine+kneipenabend-mit-dem-beispiel-thekenteam'],
  ['freigabe', '/panel/pages/termine+demo-termin-zur-freigabe'],
  ['anfragen', '/panel/pages/anfragen'],
  ['team', '/panel/pages/thekenteams+beispiel-thekenteam'],
  ['stammdaten', '/panel/site?tab=masterdata'],
  ['impressum', '/panel/pages/impressum'],
  ['benutzer', '/panel/users'],
  ['system', '/panel/system'],
];

async function login(page, credentials) {
  const [email, password] = credentials.split(':');
  await page.goto(base + '/panel/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(/panel\/(site|pages)/, { timeout: 15000 });
}

(async () => {
  if (shots) fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch();

  for (const [role, credentials] of Object.entries(accounts)) {
    if (!credentials) continue;
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    await login(page, credentials);

    for (const [name, url] of views) {
      errors.length = 0;
      await page.goto(base + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      const text = await page.locator('body').innerText();
      const errorBox = await page.locator('.k-error-view, .k-notification[data-theme=negative], .k-section-error, .k-box[data-theme=negative]').count();
      const denied = /Zugriff verweigert|keine Berechtigung|nicht gefunden|access|not allowed/i.test(text) && text.length < 2000;

      if (shots) await page.screenshot({ path: path.join(outDir, `panel-${role}-${name}.png`), fullPage: false });

      const expectDenied = role === 'moderator' && ['impressum', 'benutzer', 'system'].includes(name);

      if (expectDenied) {
        if (!denied && errorBox === 0 && /Speichern|Impressum|Benutzer|System/.test(text) && name !== 'impressum') {
          failures.push(`${role} ${name}: Bereich sollte für Moderation gesperrt sein`);
        }
        if (name === 'impressum' && /Angaben gemäß/.test(text)) failures.push(`${role}: Impressum sichtbar`);
        continue;
      }

      if (errorBox > 0) failures.push(`${role} ${name}: Fehlerhinweis im Panel: ${text.slice(0, 300).replace(/\s+/g, ' ')}`);
      const relevant = errors.filter((e) => !/Failed to load resource/.test(e));
      if (relevant.length) failures.push(`${role} ${name}: ${relevant.join(' | ')}`);
      if (role === 'moderator' && name === 'stammdaten' && /Empfänger der Terminanfragen/.test(text)) {
        failures.push('moderator: Einstellungen sichtbar');
      }
    }

    // Menü der Moderation ohne Benutzer und System
    if (role === 'moderator') {
      await page.goto(base + '/panel/site');
      const menu = await page.locator('.k-panel-menu').innerText();
      if (/Benutzer|System/.test(menu)) failures.push('moderator: Menü zeigt Benutzer/System');
    }

    await context.close();
  }

  await browser.close();

  if (failures.length) {
    console.error('✘ ' + failures.length + ' Probleme:\n  ' + failures.join('\n  '));
    process.exit(1);
  }

  console.log('✔ Panel-Prüfung für ' + Object.keys(accounts).filter((k) => accounts[k]).join(' und '));
})();
