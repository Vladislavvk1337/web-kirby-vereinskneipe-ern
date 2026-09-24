<?php
/**
 * Filter nach Terminart und Verfügbarkeit – normales GET-Formular mit
 * Absende-Knopf (kein automatisches Absenden bei Auswahl).
 *
 * @var array $filters ['art' => …, 'verfuegbarkeit' => …, 'jahr' => …, 'monat' => …, 'zeitraum' => …]
 */
use Kneipe\EventStatus;

$categories = array_diff_key(EventStatus::CATEGORIES, ['privat' => true]);
$availability = [
	''            => 'Alle Termine',
	'frei'        => 'Nur freie Termine',
	'bestaetigt'  => 'Nur bestätigte Termine',
	'abgesagt'    => 'Abgesagte Veranstaltungen',
	'geschlossen' => 'Geschlossene Tage',
];
$active = $filters['art'] !== '' || $filters['verfuegbarkeit'] !== '';
?>
<form class="filters" method="get" action="<?= $page->url() ?>" aria-labelledby="filter-titel">
  <h2 class="filters__title" id="filter-titel">Termine filtern</h2>
  <?php foreach (['jahr', 'monat', 'zeitraum'] as $keep): ?>
  <?php if ($filters[$keep] !== ''): ?><input type="hidden" name="<?= $keep ?>" value="<?= esc($filters[$keep]) ?>"><?php endif ?>
  <?php endforeach ?>
  <div class="filters__field">
    <label for="filter-art">Terminart</label>
    <select id="filter-art" name="art">
      <option value="">Alle Terminarten</option>
      <?php foreach ($categories as $key => $label): ?>
      <option value="<?= $key ?>"<?= $filters['art'] === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="filters__field">
    <label for="filter-verfuegbarkeit">Verfügbarkeit</label>
    <select id="filter-verfuegbarkeit" name="verfuegbarkeit">
      <?php foreach ($availability as $key => $label): ?>
      <option value="<?= $key ?>"<?= $filters['verfuegbarkeit'] === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="filters__actions">
    <button class="button button--secondary" type="submit">Filter anwenden</button>
    <?php if ($active): ?>
    <a class="button button--quiet" href="<?= $page->url() ?>">Filter zurücksetzen</a>
    <?php endif ?>
  </div>
</form>
