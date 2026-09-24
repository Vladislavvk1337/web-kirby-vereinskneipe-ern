<?php
/**
 * Kalender: Liste (Standard, mobil) und Monatsansicht (größere
 * Bildschirme), Filter, Monats- und Jahresnavigation, Rückblick.
 */
use Kneipe\Calendar;

snippet('header');
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()], slots: true) ?>
  <p class="page-head__links">
    <a class="link-inline" href="<?= url('termine.ics') ?>"><?php snippet('icon', ['name' => 'calendar']) ?>Kalender abonnieren (iCalendar)</a>
    <a class="link-inline" href="<?= url('termin-anfragen') ?>"><?php snippet('icon', ['name' => 'free']) ?>Freien Termin anfragen</a>
  </p>
<?php endsnippet() ?>

<div class="container calendar" id="kalender">
  <div class="calendar__tools">
    <?php snippet('calendar/filters', ['filters' => $filters]) ?>
    <details class="calendar__jump"<?= $filters['monat'] !== '' ? ' open' : '' ?>>
      <summary>Zu Monat und Jahr springen</summary>
      <?php snippet('calendar/jump', ['year' => $year, 'month' => $month, 'filters' => $filters]) ?>
    </details>
  </div>

  <?php if ($filters['zeitraum'] !== 'vergangen'): ?>
  <section class="calendar__month" aria-labelledby="monat-titel">
    <h2 id="monat-titel" class="visually-hidden">Monatsansicht</h2>
    <?php snippet('calendar/nav', ['year' => $year, 'month' => $month, 'urlFor' => $urlFor]) ?>
    <?php snippet('calendar/month', ['year' => $year, 'month' => $month, 'events' => $monthEvents]) ?>
  </section>
  <?php endif ?>

  <section class="calendar__list" aria-labelledby="liste-titel">
    <h2 id="liste-titel"><?= esc($listTitle) ?></h2>
    <?php if ($filters['art'] !== '' || $filters['verfuegbarkeit'] !== ''): ?>
    <p class="calendar__count" role="status"><?= $listEvents->count() ?> <?= $listEvents->count() === 1 ? 'Termin' : 'Termine' ?> mit den gewählten Filtern.</p>
    <?php endif ?>

    <?php if ($groups === []): ?>
      <?php snippet('empty-state', [
        'title'    => $filters['verfuegbarkeit'] === 'frei' ? 'In diesem Zeitraum ist kein Termin frei.' : 'Hier stehen noch keine Termine.',
        'text'     => 'Probiert einen anderen Monat oder setzt die Filter zurück. Eure Gruppe möchte einen Abend übernehmen? Dann schlagt einfach ein Wunschdatum vor.',
        'linkUrl'  => url('termin-anfragen'),
        'linkText' => 'Wunschtermin vorschlagen',
      ]) ?>
    <?php else: ?>
      <?php foreach ($groups as $group): ?>
      <h3 class="calendar__group"><?= esc($group['label']) ?></h3>
      <ul class="event-list">
        <?php foreach ($group['events'] as $event): ?>
        <?php snippet('event-item', ['event' => $event, 'level' => 4]) ?>
        <?php endforeach ?>
      </ul>
      <?php endforeach ?>
    <?php endif ?>

    <?php if ($filters['monat'] !== '' || $filters['zeitraum'] !== ''): ?>
    <p><a class="link-arrow" href="<?= $page->url() ?>">Alle kommenden Termine anzeigen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
    <?php endif ?>
  </section>

  <?php if ($past && $past->count()): ?>
  <section class="calendar__past" aria-labelledby="rueckblick-titel">
    <h2 id="rueckblick-titel">Rückblick</h2>
    <p>Diese Abende liegen schon hinter uns.</p>
    <ul class="event-list event-list--compact">
      <?php foreach ($past as $event): ?>
      <?php snippet('event-item', ['event' => $event]) ?>
      <?php endforeach ?>
    </ul>
    <p><a class="link-arrow" href="<?= $page->url() ?>?zeitraum=vergangen#kalender">Alle vergangenen Termine<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </section>
  <?php endif ?>
</div>
<?php snippet('footer') ?>
