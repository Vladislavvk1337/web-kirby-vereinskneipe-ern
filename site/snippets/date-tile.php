<?php
/**
 * Datumskachel für Listen.
 *
 * @var EventPage $event
 */
$start = $event->startTimestamp();

if ($start === null) {
	return;
}
?>
<time class="date-tile" datetime="<?= date('c', $start) ?>">
  <span class="date-tile__weekday"><?= kneipe()->formatDate($start, 'EEE') ?></span>
  <span class="date-tile__day"><?= date('j', $start) ?></span>
  <span class="date-tile__month"><?= kneipe()->formatDate($start, 'MMM') ?></span>
</time>
