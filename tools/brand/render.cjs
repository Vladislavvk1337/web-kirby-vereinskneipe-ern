/**
 * Erzeugt Rastergrafiken aus den SVG-Vorlagen und die Platzhalterbilder
 * für die Demo-Inhalte. Nur für die Entwicklung – die Website braucht
 * zur Laufzeit weder Node.js noch dieses Skript.
 *
 *   NODE_PATH="$(npm root -g)" node tools/brand/render.cjs
 *
 * Voraussetzung: Playwright mit Chromium (npm i -g playwright).
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const brand = path.join(root, 'user/themes/kneipe/images/brand');
const fonts = path.join(root, 'user/themes/kneipe/fonts');

// Schriften als Data-URL einbetten (file://-Adressen blockiert Chromium hier)
const font = (file) => 'data:font/woff2;base64,' + fs.readFileSync(path.join(fonts, file)).toString('base64');
const fontCss = `
@font-face { font-family: "Fraunces"; src: url("${font('fraunces-latin-var.woff2')}") format("woff2"); font-weight: 100 900; }
@font-face { font-family: "Atkinson Hyperlegible Next"; src: url("${font('atkinson-hyperlegible-next-latin-var.woff2')}") format("woff2"); font-weight: 200 800; }
html, body { margin: 0; padding: 0; }
svg { display: block; }
`;

const page = (svg, w, h) => `<!doctype html><html><head><style>${fontCss} body{width:${w}px;height:${h}px;overflow:hidden}</style></head><body>${svg}</body></html>`;

// Platzhalter-Szenen: gezeichnet in den Markenfarben, deutlich beschriftet
const scene = (variant) => {
  const W = 1600, H = 1000;
  const sky = {
    theke: ['#f5eee1', '#edc06f'],
    abend: ['#1d4764', '#122e42'],
    team: ['#f7e7c7', '#ebdfc9'],
    rueckblick: ['#edc06f', '#e0a43a'],
  }[variant];
  const dark = variant === 'abend';
  const lights = variant === 'abend'
    ? Array.from({ length: 18 }, (_, i) => {
        const x = 120 + i * 78, y = 250 + Math.sin(i / 2.2) * 40;
        return `<circle cx="${x}" cy="${y}" r="12" fill="#edc06f"/><circle cx="${x}" cy="${y}" r="28" fill="#edc06f" opacity=".18"/>`;
      }).join('') + '<path d="M80 240 Q 800 330 1520 240" fill="none" stroke="#c9d8e2" stroke-width="3"/>'
    : '';
  const counter = variant === 'rueckblick' ? '' : `
    <rect x="0" y="700" width="${W}" height="300" fill="${dark ? '#0c2232' : '#17384f'}"/>
    <rect x="0" y="690" width="${W}" height="26" fill="#b87a14"/>
    ${[300, 420, 540].map((x) => `<rect x="${x}" y="560" width="26" height="130" rx="8" fill="#c9d8e2"/><rect x="${x - 20}" y="545" width="66" height="26" rx="10" fill="#e4ecf1"/>`).join('')}
    ${[1000, 1090, 1180, 1270].map((x, i) => `<path d="M${x} ${690 - 110 - (i % 2) * 20}h60l-8 ${110 + (i % 2) * 20}h-44z" fill="#e0a43a" opacity=".9"/><path d="M${x} ${690 - 110 - (i % 2) * 20}h60v26h-60z" fill="#fffdf9" opacity=".9"/>`).join('')}`;
  const hills = `
    <path d="M0 520C160 430 320 470 480 420S800 330 980 400s380 20 620-40V${H}H0z" fill="${dark ? '#17384f' : '#c9d8e2'}"/>
    <path d="M0 600c180-60 360-40 540-80s380-60 560-10 320 20 500-10V${H}H0z" fill="${dark ? '#122e42' : '#3f6f8f'}" opacity=".7"/>`;
  const bridge = variant === 'rueckblick' ? `
    <path d="M0 760c300-30 600-20 900-30s500-10 700-6V${H}H0z" fill="#17384f"/>
    <path d="M0 800c260-10 560-6 820-14s520-8 780-2v40c-260-6-520-2-780 6S260 846 0 850z" fill="#fbf7f0" opacity=".55"/>
    <path d="M520 790c80-170 480-170 560 0" fill="none" stroke="#122e42" stroke-width="26" stroke-linecap="round"/>
    <path d="M460 790h680" stroke="#122e42" stroke-width="20" stroke-linecap="round"/>` : '';
  const label = `
    <g transform="translate(${W - 560} 60)">
      <rect width="500" height="92" rx="46" fill="#fffdf9" opacity=".95"/>
      <text x="250" y="58" text-anchor="middle" font-family="Atkinson Hyperlegible Next" font-size="34" font-weight="700" fill="#23272b">Platzhalterbild – Foto folgt</text>
    </g>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
    <defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${sky[0]}"/><stop offset="1" stop-color="${sky[1]}"/></linearGradient></defs>
    <rect width="${W}" height="${H}" fill="url(#s)"/>
    ${hills}${lights}${bridge}${counter}${label}
  </svg>`;
};

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ deviceScaleFactor: 1 });
  const p = await ctx.newPage();

  const shot = async (svg, w, h, out, type = 'png', quality) => {
    await p.setViewportSize({ width: w, height: h });
    await p.setContent(page(svg, w, h), { waitUntil: 'load' });
    await p.evaluate(async () => {
      await Promise.all([...document.fonts].map((f) => f.load()));
      await document.fonts.ready;
    });
    await p.screenshot({ path: out, type, quality, clip: { x: 0, y: 0, width: w, height: h } });
    console.log('✔', path.relative(root, out));
  };

  const favicon = fs.readFileSync(path.join(brand, 'favicon.svg'), 'utf8');
  const sized = (svg, s) => svg.replace(/width="\d+" height="\d+"/, `width="${s}" height="${s}"`);
  await shot(sized(favicon, 32), 32, 32, path.join(brand, 'favicon-32.png'));
  await shot(sized(favicon, 180), 180, 180, path.join(brand, 'apple-touch-icon.png'));
  await shot(sized(favicon, 512), 512, 512, path.join(brand, 'icon-512.png'));

  const og = fs.readFileSync(path.join(brand, 'og-template.svg'), 'utf8');
  await shot(og, 1200, 630, path.join(brand, 'og-default.png'));

  const targets = {
    theke: 'content/home/platzhalter-theke.jpg',
    abend: 'content/1_termine/20261002_kneipenabend-mit-dem-beispiel-thekenteam/platzhalter-abend.jpg',
    team: 'content/2_thekenteams/0_beispiel-thekenteam/platzhalter-team.jpg',
    rueckblick: 'content/aktuelles/20260915_rueckblick-sommerabend/platzhalter-rueckblick.jpg',
  };

  for (const [variant, file] of Object.entries(targets)) {
    await shot(scene(variant), 1600, 1000, path.join(root, file), 'jpeg', 72);
  }

  await browser.close();
})();
