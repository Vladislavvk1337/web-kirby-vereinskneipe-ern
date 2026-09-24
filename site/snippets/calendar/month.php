<?php
/**
 * Monatsansicht als Tabelle (ab mittlerer Bildschirmbreite sichtbar).
 * Dieselben Termine stehen zusätzlich in der Liste darunter.
 *
 * @var int $year
 * @var int $month
 * @var Kirby\Cms\Pages $events
 */
use Kneipe\Calendar;

$weeks = Calendar::monthGrid($year, $month);
$today = date('Y-m-d');
$short = ['frei' => 'frei', 'bestaetigt' => '', 'abgesagt' => 'abgesagt', 'geschlossen' => 'geschlossen'];
?>
<div class="month-grid" id="monatsansicht">
  <table>
    <caption class="visually-hidden">Monatsübersicht <?= Calendar::monthLabel($year, $month) ?> – alle Termine stehen auch in der Liste unter der Tabelle.</caption>
    <thead>
      <tr>
        <?php foreach (Calendar::WEEKDAYS as $i => $day): ?>
        <th scope="col"><abbr title="<?= Calendar::WEEKDAYS_LONG[$i] ?>"><?= $day ?></abbr></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($weeks as $week): ?>
      <tr>
        <?php foreach ($week as $day):
          $dayEvents = $events->filter(fn ($e) => $e->startTimestamp() < $day['end'] && $e->endTimestamp() > $day['start']);
          $classes = ['month-grid__day'];
          if (!$day['inMonth']) $classes[] = 'is-outside';
          if ($day['date'] === $today) $classes[] = 'is-today';
          if ($day['date'] < $today) $classes[] = 'is-past';
        ?>
        <td class="<?= implode(' ', $classes) ?>">
          <span class="month-grid__date"><?= $day['day'] ?><?= $day['date'] === $today ? '<span class="visually-hidden"> (heute)</span>' : '' ?></span>
          <?php if ($dayEvents->count()): ?>
          <ul>
            <?php foreach ($dayEvents as $event): ?>
            <li class="month-grid__event month-grid__event--<?= $event->publicStatus() ?>">
              <a href="<?= $event->url() ?>">
                <?php if (!$event->isAllDay()): ?><span class="month-grid__time"><?= date('H:i', $event->startTimestamp()) ?></span><?php endif ?>
                <span class="month-grid__title"><?= esc($event->publicTitle()) ?></span>
                <?php if ($short[$event->publicStatus()] ?? ''): ?><span class="month-grid__flag">(<?= $short[$event->publicStatus()] ?>)</span><?php endif ?>
              </a>
            </li>
            <?php endforeach ?>
          </ul>
          <?php endif ?>
        </td>
        <?php endforeach ?>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
