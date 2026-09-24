<?php
/**
 * Termin in einer Liste. Die ganze Zeile ist anklickbar, der Link liegt
 * auf der Überschrift.
 *
 * @var EventPage $event
 * @var int|null $level Überschriftenebene (Standard 3)
 */
$level = $level ?? 3;
$team  = $event->publicTeam();
$past  = $event->isPast();
?>
<li class="event-item event-item--<?= $event->publicStatus() ?><?= $past ? ' event-item--past' : '' ?>">
  <?php snippet('date-tile', ['event' => $event]) ?>
  <div class="event-item__body">
    <h<?= $level ?> class="event-item__title"><a href="<?= $event->url() ?>"><?= esc($event->publicTitle()) ?></a></h<?= $level ?>>
    <p class="event-item__meta">
      <span><?php snippet('icon', ['name' => 'clock']) ?><?= esc(kneipe()->timeRange($event)) ?></span>
      <?php if ($team): ?>
      <span><?php snippet('icon', ['name' => 'users']) ?><?= esc($team->title()) ?></span>
      <?php elseif ($event->isPrivateEvent() === false): ?>
      <span class="event-item__type"><?= esc($event->eventTypeLabel()) ?></span>
      <?php endif ?>
    </p>
  </div>
  <div class="event-item__status"><?php snippet('status', ['event' => $event]) ?></div>
</li>
