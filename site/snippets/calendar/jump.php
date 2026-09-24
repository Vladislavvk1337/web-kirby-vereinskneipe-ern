<?php
/**
 * Direkt zu Monat und Jahr springen (GET-Formular).
 *
 * @var int $year
 * @var int $month
 * @var array $filters
 */
use Kneipe\Calendar;

$current = (int)date('Y');
?>
<form class="jump" method="get" action="<?= $page->url() ?>">
  <?php foreach (['art', 'verfuegbarkeit'] as $keep): ?>
  <?php if ($filters[$keep] !== ''): ?><input type="hidden" name="<?= $keep ?>" value="<?= esc($filters[$keep]) ?>"><?php endif ?>
  <?php endforeach ?>
  <div class="filters__field">
    <label for="jump-monat">Monat</label>
    <select id="jump-monat" name="monat">
      <?php foreach (Calendar::MONTHS as $num => $label): ?>
      <option value="<?= $num ?>"<?= $num === $month ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="filters__field">
    <label for="jump-jahr">Jahr</label>
    <select id="jump-jahr" name="jahr">
      <?php for ($y = $current - 2; $y <= $current + 2; $y++): ?>
      <option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option>
      <?php endfor ?>
    </select>
  </div>
  <div class="filters__actions">
    <button class="button button--secondary" type="submit">Monat anzeigen</button>
  </div>
</form>
