<?php
/** Kontakt und Anfahrt – Kartenlink statt eingebetteter Karte */
snippet('header');
$kneipe = kneipe();
$hours  = $site->openinghours()->toStructure();
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container section contact">
  <section class="contact__card" aria-labelledby="adresse-titel">
    <h2 id="adresse-titel">Adresse</h2>
    <address>
      <strong><?= esc($site->venue()->or($kneipe->name())) ?></strong><br>
      <?= $site->street()->isNotEmpty() ? esc($site->street()) : '<mark class="placeholder">[Straße und Hausnummer]</mark>' ?><br>
      <?= $site->postalcode()->isNotEmpty() ? esc($site->postalcode()) : '<mark class="placeholder">[PLZ]</mark>' ?> <?= esc($site->city()->or('Erndtebrück')) ?>
    </address>
    <?php if ($map = $kneipe->mapUrl()): ?>
    <p><a class="button button--secondary" href="<?= esc($map) ?>" rel="noopener"><?php snippet('icon', ['name' => 'pin']) ?>Auf der Karte ansehen<span class="visually-hidden"> (OpenStreetMap, externe Website)</span></a></p>
    <p class="muted small">Der Link führt zu OpenStreetMap. Erst beim Anklicken werden Daten an OpenStreetMap übertragen.</p>
    <?php endif ?>
  </section>

  <section class="contact__card" aria-labelledby="kontakt-titel">
    <h2 id="kontakt-titel">So erreicht ihr uns</h2>
    <dl class="fact-list">
      <div><dt><?php snippet('icon', ['name' => 'mail']) ?>E-Mail</dt><dd><?= $site->email()->isNotEmpty() ? Html::email($site->email()->value()) : '<mark class="placeholder">[E-Mail-Adresse]</mark>' ?></dd></div>
      <div><dt><?php snippet('icon', ['name' => 'phone']) ?>Telefon</dt><dd><?= $site->phone()->isNotEmpty() ? Html::tel($site->phone()->value()) : '<mark class="placeholder">[Telefonnummer]</mark>' ?></dd></div>
    </dl>
    <p>Für Thekenschichten nutzt am besten das Formular:</p>
    <p><a class="button button--primary" href="<?= url('termin-anfragen') ?>">Termin anfragen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </section>

  <section class="contact__card" aria-labelledby="zeiten-titel">
    <h2 id="zeiten-titel">Öffnungszeiten</h2>
    <?php if ($hours->count()): ?>
    <dl class="hours">
      <?php foreach ($hours as $row): ?>
      <div><dt><?= kneipe_text($row->days()->value()) ?></dt><dd><?= kneipe_text($row->time()->value()) ?><?php if ($row->note()->isNotEmpty()): ?><br><span class="hours__note"><?= kneipe_text($row->note()->value()) ?></span><?php endif ?></dd></div>
      <?php endforeach ?>
    </dl>
    <?php endif ?>
    <?php if ($site->openingnote()->isNotEmpty()): ?><p><?= kneipe_text($site->openingnote()->value()) ?></p><?php endif ?>
    <p><a class="link-arrow" href="<?= url('termine') ?>">Zum Kalender<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </section>

  <section class="contact__wide" aria-labelledby="anfahrt-titel">
    <h2 id="anfahrt-titel">Anfahrt</h2>
    <div class="prose"><?= $page->directions()->toSafeHtml() ?></div>
    <?php if ($page->accessibility()->isNotEmpty()): ?>
    <h3>Zugang</h3>
    <p><?= $page->accessibility()->toSafeText() ?></p>
    <?php endif ?>
  </section>
</div>
<?php snippet('footer') ?>
