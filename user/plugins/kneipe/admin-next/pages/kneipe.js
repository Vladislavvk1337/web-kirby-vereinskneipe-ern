/**
 * Redaktionsübersicht im Admin (Admin2 lädt diese Datei als Web Component,
 * Tag-Name aus window.__GRAV_PAGE_TAG). Daten: GET /api/v1/kneipe/overview.
 * Ohne Framework, ohne externe Ressourcen; Texte werden nur als Text
 * eingesetzt (textContent), nie als HTML.
 */
(function () {
  'use strict';

  var tag = window.__GRAV_PAGE_TAG || 'grav-kneipe--page';

  if (customElements.get(tag)) {
    return;
  }

  var sections = [
    ['requests', 'Offene Terminanfragen', 'Keine offenen Anfragen.'],
    ['approval', 'Termine zur Freigabe', 'Nichts wartet auf Freigabe.'],
    ['conflicts', 'Doppelbelegungen', 'Keine Überschneidungen.'],
    ['next', 'Nächster Öffnungstermin', 'Kein bestätigter Termin in Sicht.'],
    ['free', 'Freie Termine', 'Keine freien Termine ausgeschrieben.'],
    ['incomplete', 'Unvollständige Termine', 'Alle kommenden Termine sind vollständig.']
  ];

  var css = [
    ':host{display:block;font:inherit;color:inherit}',
    '.grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))}',
    'section{border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:.6rem;padding:1rem}',
    'h2{font-size:1rem;margin:0 0 .6rem}',
    'ul{list-style:none;margin:0;padding:0}',
    'li{padding:.45rem 0;border-top:1px solid color-mix(in srgb,currentColor 10%,transparent)}',
    'li:first-child{border-top:0}',
    'a{color:inherit;font-weight:600}',
    'a:focus-visible{outline:3px solid currentColor;outline-offset:2px}',
    '.meta{display:block;opacity:.75;font-size:.875rem}',
    '.note{display:block;font-size:.875rem;margin-top:.2rem}',
    '.info,.warn{border-radius:.6rem;padding:.8rem 1rem;margin:0 0 1rem}',
    '.info{background:color-mix(in srgb,currentColor 6%,transparent)}',
    '.warn{border:2px solid #b45309}',
    '.muted{opacity:.7;margin:0}'
  ].join('');

  function el(name, className, text) {
    var node = document.createElement(name);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function editUrl(route) {
    var base = (window.__GRAV_CONFIG__ && window.__GRAV_CONFIG__.basePath) || '/admin';
    return base + '/pages/edit' + route;
  }

  class KneipeOverview extends HTMLElement {
    connectedCallback() {
      if (this.shadowRoot) return;
      var root = this.attachShadow({ mode: 'open' });
      var style = el('style');
      style.textContent = css;
      root.appendChild(style);
      this.body = el('div');
      this.body.appendChild(el('p', 'muted', 'Übersicht wird geladen …'));
      root.appendChild(this.body);
      this.load();
    }

    load() {
      var url = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + '/kneipe/overview';
      var headers = { Accept: 'application/json' };
      if (window.__GRAV_API_TOKEN) headers.Authorization = 'Bearer ' + window.__GRAV_API_TOKEN;
      var self = this;

      fetch(url, { headers: headers, credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.json();
        })
        .then(function (json) { self.render(json.data || json); })
        .catch(function () {
          self.body.textContent = '';
          self.body.appendChild(el('p', 'warn', 'Die Übersicht konnte nicht geladen werden. Bitte die Seite neu laden.'));
        });
    }

    render(data) {
      var body = this.body;
      body.textContent = '';
      body.appendChild(el('p', 'info', data.approvalText || ''));

      (data.warnings || []).forEach(function (text) {
        body.appendChild(el('p', 'warn', 'Hinweis: ' + text));
      });

      var grid = el('div', 'grid');

      sections.forEach(function (def) {
        var items = data[def[0]] || [];
        var section = el('section');
        section.appendChild(el('h2', null, def[1] + (items.length ? ' (' + items.length + ')' : '')));

        if (!items.length) {
          section.appendChild(el('p', 'muted', def[2]));
        } else {
          var list = el('ul');
          items.forEach(function (item) {
            var li = el('li');
            var link = el('a', null, item.title || item.route);
            link.href = editUrl(item.route);
            li.appendChild(link);
            li.appendChild(el('span', 'meta', [item.when, item.status].filter(Boolean).join(' · ')));
            if (item.note) li.appendChild(el('span', 'note', item.note));
            list.appendChild(li);
          });
          section.appendChild(list);
        }

        grid.appendChild(section);
      });

      body.appendChild(grid);
      body.appendChild(el('p', 'muted', 'Anfragen werden nach ' + data.retention + ' Tagen automatisch gelöscht.'));
    }
  }

  customElements.define(tag, KneipeOverview);
})();
