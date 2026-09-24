<?php
/**
 * Seitenanfang: <head>, Sprunglink, Kopfbereich mit Hauptnavigation.
 * Alle Variablen werden an snippets/head.php weitergereicht.
 */
// Controller-Daten ($styles, $noindex, $jsonld …) und an diesen Snippet
// übergebene Werte an <head> weiterreichen
$forward = [];

foreach (['noindex', 'styles', 'metaTitle', 'metaDescription', 'canonical', 'ogImage', 'jsonld'] as $key) {
	if (isset($$key) === true) {
		$forward[$key] = $$key;
	}
}

snippet('head', $forward);

$nav = $site->children()->listed();
?>
<body class="template-<?= $page->intendedTemplate()->name() ?>">
  <a class="skip-link" href="#inhalt">Zum Inhalt springen</a>
  <?php if (isset($preview) && $preview): ?>
  <div class="preview-banner" role="note"><?php snippet('icon', ['name' => 'lock']) ?>Vorschau für die Redaktion – dieser Inhalt ist nicht öffentlich sichtbar.</div>
  <?php endif ?>
  <header class="site-header">
    <div class="container site-header__inner">
      <a class="brand" href="<?= $site->url() ?>"<?= $page->isHomePage() ? ' aria-current="page"' : '' ?>>
        <?php snippet('logo-mark') ?>
        <span class="brand__name"><?= esc(kneipe()->name()) ?></span>
      </a>
      <nav class="site-nav" aria-label="Hauptnavigation">
        <button class="site-nav__toggle" type="button" aria-expanded="false" aria-controls="hauptmenue" hidden>
          <?php snippet('icon', ['name' => 'menu', 'class' => 'site-nav__icon-open']) ?>
          <?php snippet('icon', ['name' => 'close', 'class' => 'site-nav__icon-close']) ?>
          <span>Menü</span>
        </button>
        <ul class="site-nav__list" id="hauptmenue">
          <li><a href="<?= $site->url() ?>"<?= $page->isHomePage() ? ' aria-current="page"' : '' ?>>Start</a></li>
          <?php foreach ($nav as $item): ?>
          <li><a href="<?= $item->url() ?>"<?= $item->isOpen() ? ' aria-current="' . ($item->isActive() ? 'page' : 'true') . '"' : '' ?>><?= esc($item->navtitle()->or($item->title())) ?></a></li>
          <?php endforeach ?>
        </ul>
      </nav>
    </div>
  </header>
  <main id="inhalt" tabindex="-1">
