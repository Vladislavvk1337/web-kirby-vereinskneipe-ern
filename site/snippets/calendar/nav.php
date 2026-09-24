<?php
/**
 * Monats- und Jahresnavigation (Links, ohne JavaScript bedienbar).
 *
 * @var int $year
 * @var int $month
 * @var callable $urlFor fn(int $year, int $month): string
 */
use Kneipe\Calendar;

[$py, $pm] = Calendar::shiftMonth($year, $month, -1);
[$ny, $nm] = Calendar::shiftMonth($year, $month, 1);
?>
<nav class="month-nav" aria-label="Monat wechseln">
  <a class="month-nav__step" href="<?= esc($urlFor($year - 1, $month)) ?>"><?php snippet('icon', ['name' => 'double-left']) ?><span class="visually-hidden"><?= Calendar::monthLabel($year - 1, $month) ?> (ein Jahr zurück)</span></a>
  <a class="month-nav__step" href="<?= esc($urlFor($py, $pm)) ?>"><?php snippet('icon', ['name' => 'chevron-left']) ?><span class="visually-hidden"><?= Calendar::monthLabel($py, $pm) ?> (vorheriger Monat)</span></a>
  <p class="month-nav__current" aria-live="polite"><?= Calendar::monthLabel($year, $month) ?></p>
  <a class="month-nav__step" href="<?= esc($urlFor($ny, $nm)) ?>"><span class="visually-hidden"><?= Calendar::monthLabel($ny, $nm) ?> (nächster Monat)</span><?php snippet('icon', ['name' => 'chevron-right']) ?></a>
  <a class="month-nav__step" href="<?= esc($urlFor($year + 1, $month)) ?>"><span class="visually-hidden"><?= Calendar::monthLabel($year + 1, $month) ?> (ein Jahr weiter)</span><?php snippet('icon', ['name' => 'double-right']) ?></a>
</nav>
