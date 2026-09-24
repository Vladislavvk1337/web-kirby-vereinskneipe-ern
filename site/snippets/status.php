<?php
/**
 * Öffentlicher Status als Text mit Symbol – nie nur über Farbe.
 *
 * @var EventPage $event
 */
$key = $event->publicStatus();

if ($key === null) {
	return;
}

$icons = ['frei' => 'free', 'bestaetigt' => 'check', 'abgesagt' => 'cancel', 'geschlossen' => 'lock'];
?>
<span class="status status--<?= $key ?>"><?php snippet('icon', ['name' => $icons[$key]]) ?><span><?= esc($event->publicStatusLabel()) ?></span></span>
