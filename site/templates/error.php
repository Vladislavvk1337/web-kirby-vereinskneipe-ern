<?php
/** 404 und andere Fehler – freundlich, mit Wegen weiter */
$code = $errorCode ?? 404;
snippet('header', ['noindex' => true]);
?>
<?php snippet('page-head', [
  'title'   => $code === 404 ? $page->title()->value() : 'Da ist etwas schiefgegangen',
  'eyebrow' => 'Fehler ' . $code,
  'intro'   => $code === 404 ? $page->text()->value() : 'Bitte versucht es in ein paar Minuten noch einmal.',
]) ?>
<div class="container section">
  <ul class="link-cards">
    <li><a href="<?= $site->url() ?>"><?php snippet('icon', ['name' => 'arrow']) ?>Zur Startseite</a></li>
    <li><a href="<?= url('termine') ?>"><?php snippet('icon', ['name' => 'calendar']) ?>Alle Termine</a></li>
    <li><a href="<?= url('termin-anfragen') ?>"><?php snippet('icon', ['name' => 'free']) ?>Termin anfragen</a></li>
    <li><a href="<?= url('kontakt') ?>"><?php snippet('icon', ['name' => 'mail']) ?>Kontakt</a></li>
  </ul>
</div>
<?php snippet('footer') ?>
