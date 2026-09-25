/**
 * Admin-Prüfung (Admin2) aus Sicht von Administration und Moderation:
 * Anmeldung, Redaktionsübersicht, Terminformular, Einstellungen. Fehler:
 * Skriptfehler im Browser und Serverfehler (5xx) der API.
 *
 *   ADMIN=admin:<passwort> MOD=moderation:<passwort> BASE_URL=http://localhost:8000 \
 *     NODE_PATH="$(npm root -g)" node tests/browser/admin.cjs [--screenshots]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.env.BASE_URL || 'http://localhost:8000';
const screenshots = process.argv.includes('--screenshots');
const dir = path.join(__dirname, '..', 'screenshots');
const accounts = [['administration', process.env.ADMIN], ['moderation', process.env.MOD]].filter(([, v]) => v);

if (!accounts.length) {
  console.error('ADMIN=benutzer:passwort und/oder MOD=benutzer:passwort setzen.');
  process.exit(2);
}

(async () => {
  const browser = await chromium.launch();
  const problems = [];
  if (screenshots) fs.mkdirSync(dir, { recursive: true });

  for (const [role, credentials] of accounts) {
    const [user, ...rest] = credentials.split(':');
    const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
    page.on('pageerror', (e) => problems.push(`${role}: Skriptfehler ${e.message}`));
    page.on('response', (r) => { if (r.status() >= 500) problems.push(`${role}: ${r.status()} ${r.url().replace(base, '')}`); });

    await page.goto(base + '/admin', { waitUntil: 'networkidle' });
    await page.locator('input[type="password"]').first().waitFor({ timeout: 15000 });
    await page.locator('input[type="text"], input[name="username"], input[autocomplete="username"]').first().fill(user);
    await page.locator('input[type="password"]').first().fill(rest.join(':'));
    await page.keyboard.press('Enter');
    await page.waitForTimeout(3000);

    const steps = [
      ['redaktion', '/admin/plugin/kneipe', 'Freigabemodus'],
      ['termin', '/admin/pages/edit/termine/demo-reserviert', 'Organisatorischer Status'],
      ['einstellungen', '/admin/pages/edit/einstellungen', null],
    ];

    for (const [name, url, expect] of steps) {
      await page.goto(base + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);
      if (expect) {
        const text = await page.locator('body').innerText();
        // Die Übersicht rendert in einem Shadow Root
        const shadow = await page.evaluate(() => Array.from(document.querySelectorAll('*')).map((el) => el.shadowRoot ? el.shadowRoot.textContent : '').join(' '));
        if (!(text + shadow).includes(expect)) problems.push(`${role}: „${expect}“ fehlt auf ${url}`);
      }
      if (screenshots) await page.screenshot({ path: path.join(dir, `admin-${role}-${name}.png`), fullPage: true });
    }

    await page.close();
  }

  await browser.close();

  if (problems.length) {
    console.error('✘ Admin-Prüfung:\n  ' + problems.join('\n  '));
    process.exit(1);
  }

  console.log(`✔ Admin-Prüfung: ${accounts.map(([r]) => r).join(', ')} – Anmeldung, Redaktionsübersicht, Terminformular, Einstellungen`);
})();
