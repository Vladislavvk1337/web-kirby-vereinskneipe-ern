/**
 * Progressive Verbesserung – die Website funktioniert vollständig ohne
 * dieses Skript. Es klappt das Menü auf kleinen Bildschirmen ein und
 * setzt nach einem Formularfehler den Fokus auf die Fehlerübersicht.
 */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  // Hauptmenü: Knopf zum Auf- und Zuklappen
  var toggle = document.querySelector('.site-nav__toggle');
  var list = document.getElementById('hauptmenue');

  if (toggle && list) {
    toggle.hidden = false;

    var setOpen = function (open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      list.classList.toggle('is-open', open);
    };

    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  // Fehlerübersicht oder Formularhinweis nach dem Absenden fokussieren
  var summary = document.getElementById('fehleruebersicht') || document.getElementById('formular-hinweis');

  if (summary) {
    summary.focus();
  }
})();
