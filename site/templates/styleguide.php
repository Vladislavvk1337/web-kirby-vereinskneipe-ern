<?php
/**
 * Design-System-Übersicht (/bausteine) – nicht verlinkt, nicht indexiert.
 * Zeigt Farben, Schrift, Knöpfe, Status, Listen, Formularfelder, Hinweise.
 */
snippet('header', ['noindex' => true, 'styles' => ['form', 'calendar']]);
$sample = kneipe()->upcomingEvents(1, true)->first();
$tokens = [
	['--ui-eder-900', 'Eder-Blau, dunkel', 'Kopf- und Fußbereich, Überschriften'],
	['--ui-eder-700', 'Eder-Blau', 'Links, sekundäre Knöpfe'],
	['--ui-amber-400', 'Bernstein', 'Primäre Knöpfe, Akzente'],
	['--ui-amber-800', 'Bernstein, dunkel', 'Akzenttext auf Creme'],
	['--ui-cream-50', 'Creme', 'Seitenhintergrund'],
	['--ui-cream-200', 'Creme, dunkler', 'Flächen, Linien'],
	['--ui-ink', 'Anthrazit', 'Fließtext'],
	['--ui-moss-600', 'Moosgrün', 'Status „frei“ – sparsam'],
	['--ui-danger', 'Ziegelrot', 'Fehler, „abgesagt“'],
];
?>
<?php snippet('page-head', ['title' => 'Bausteine', 'eyebrow' => 'Design-System', 'intro' => 'Alle Gestaltungselemente der Website auf einen Blick. Farben, Schriften und Abstände stehen ausschließlich in assets/css/tokens.css.']) ?>
<div class="container section styleguide">
  <section aria-labelledby="sg-farben">
    <h2 id="sg-farben">Farben</h2>
    <ul class="swatches">
      <?php foreach ($tokens as [$var, $name, $use]): ?>
      <li class="swatch"><span class="swatch__color swatch__color<?= str_replace('--ui', '', $var) ?>"></span><strong><?= $name ?></strong><code><?= $var ?></code><span class="muted small"><?= $use ?></span></li>
      <?php endforeach ?>
    </ul>
  </section>

  <section aria-labelledby="sg-schrift">
    <h2 id="sg-schrift">Schrift</h2>
    <p class="eyebrow">Eyebrow · Atkinson Hyperlegible Next</p>
    <p class="sg-display">Fraunces – Überschriften mit Charakter</p>
    <h3>Überschrift dritter Ordnung</h3>
    <p class="lead">Einleitungstext: etwas größer, für den ersten Absatz einer Seite.</p>
    <p>Fließtext in Atkinson Hyperlegible Next – entwickelt für besonders gute Lesbarkeit, auch bei eingeschränktem Sehvermögen. Mindestens 16 px, fluide bis 19 px. <a href="#">Ein Textlink</a> und <strong>fette Hervorhebung</strong>.</p>
    <p><mark class="placeholder">[Platzhalter: So erscheinen fehlende Angaben]</mark></p>
  </section>

  <section aria-labelledby="sg-knoepfe">
    <h2 id="sg-knoepfe">Knöpfe und Links</h2>
    <p class="sg-row">
      <a class="button button--primary" href="#">Primär<?php snippet('icon', ['name' => 'arrow']) ?></a>
      <a class="button button--secondary" href="#">Sekundär</a>
      <a class="button button--quiet" href="#">Zurückhaltend</a>
      <a class="link-arrow" href="#">Link mit Pfeil<?php snippet('icon', ['name' => 'arrow']) ?></a>
    </p>
    <p class="muted small">Pro Abschnitt höchstens ein primärer Knopf. Alle Ziele mindestens 44 × 44 px.</p>
  </section>

  <section aria-labelledby="sg-status">
    <h2 id="sg-status">Status</h2>
    <p class="sg-row">
      <span class="status status--frei"><?php snippet('icon', ['name' => 'free']) ?><span>Termin frei</span></span>
      <span class="status status--bestaetigt"><?php snippet('icon', ['name' => 'check']) ?><span>Termin bestätigt</span></span>
      <span class="status status--abgesagt"><?php snippet('icon', ['name' => 'cancel']) ?><span>Veranstaltung abgesagt</span></span>
      <span class="status status--geschlossen"><?php snippet('icon', ['name' => 'lock']) ?><span>Geschlossen</span></span>
    </p>
  </section>

  <section aria-labelledby="sg-liste">
    <h2 id="sg-liste">Terminliste</h2>
    <?php if ($sample): ?>
    <ul class="event-list"><?php snippet('event-item', ['event' => $sample]) ?></ul>
    <?php endif ?>
    <?php snippet('empty-state', ['title' => 'Leerzustand', 'text' => 'So sieht es aus, wenn es nichts anzuzeigen gibt.']) ?>
  </section>

  <section aria-labelledby="sg-hinweise">
    <h2 id="sg-hinweise">Hinweise</h2>
    <div class="notice"><?php snippet('icon', ['name' => 'info']) ?><p>Neutraler Hinweis.</p></div>
    <div class="notice notice--success"><?php snippet('icon', ['name' => 'check']) ?><p>Erfolg.</p></div>
    <div class="notice notice--warning"><?php snippet('icon', ['name' => 'alert']) ?><p>Warnung.</p></div>
    <div class="notice notice--danger"><?php snippet('icon', ['name' => 'cancel']) ?><p>Fehler oder Absage.</p></div>
  </section>

  <section aria-labelledby="sg-formular">
    <h2 id="sg-formular">Formularfelder</h2>
    <form class="form" action="#">
      <?php snippet('form/field', ['name' => 'beispiel', 'label' => 'Textfeld', 'required' => true, 'values' => [], 'errors' => [], 'hint' => 'Hinweis zum Feld.']) ?>
      <?php snippet('form/field', ['name' => 'fehler', 'label' => 'Feld mit Fehler', 'required' => true, 'values' => ['fehler' => 'falsch@'], 'errors' => ['fehler' => 'So erscheint eine Fehlermeldung direkt am Feld.']]) ?>
    </form>
  </section>

  <section aria-labelledby="sg-logo">
    <h2 id="sg-logo">Logo</h2>
    <p class="sg-row sg-logos">
      <img src="<?= url('assets/brand/logo.svg') ?>" alt="Bildmarke farbig" width="96" height="96">
      <img src="<?= url('assets/brand/logo-mono.svg') ?>" alt="Bildmarke einfarbig" width="96" height="96">
      <img src="<?= url('assets/brand/wordmark.svg') ?>" alt="Wortmarke" width="320" height="96">
    </p>
  </section>
</div>
<?php snippet('footer') ?>
