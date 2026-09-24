<?php

use Kneipe\EventStatus;
use Kneipe\Overlap;
use Kneipe\Workflow;

test('interne Status sind nie öffentlich', function () {
	foreach (['angefragt', 'reserviert', 'freigabe'] as $status) {
		assert_same(null, EventStatus::publicKey($status, 'kneipenabend'), $status);
	}
	assert_same(null, EventStatus::publicKey('unbekannt', 'kneipenabend'));
});

test('öffentliche Status werden korrekt abgebildet', function () {
	assert_same('frei', EventStatus::publicKey('frei', 'kneipenabend'));
	assert_same('bestaetigt', EventStatus::publicKey('bestaetigt', 'kultur'));
	assert_same('bestaetigt', EventStatus::publicKey('veroeffentlicht', 'thekenteam'));
	assert_same('bestaetigt', EventStatus::publicKey('archiviert', 'thekenteam'));
	assert_same('abgesagt', EventStatus::publicKey('abgesagt', 'kultur'));
	assert_same('geschlossen', EventStatus::publicKey('geschlossen', 'kneipenabend'));
	assert_same('geschlossen', EventStatus::publicKey('bestaetigt', 'geschlossen'));
	assert_same('geschlossen', EventStatus::publicKey('bestaetigt', 'privat'), 'private Feier ist für Gäste geschlossen');
	assert_same('Termin frei', EventStatus::publicLabel('frei'));
});

test('Überschneidungen werden erkannt, Absagen ignoriert', function () {
	$base = ['id' => 'a', 'start' => 100, 'end' => 200, 'orgstatus' => 'bestaetigt'];
	$others = [
		['id' => 'b', 'start' => 150, 'end' => 250, 'orgstatus' => 'angefragt'],
		['id' => 'c', 'start' => 200, 'end' => 300, 'orgstatus' => 'frei'],
		['id' => 'd', 'start' => 120, 'end' => 130, 'orgstatus' => 'abgesagt'],
		['id' => 'e', 'start' => 50, 'end' => 400, 'orgstatus' => 'archiviert'],
		$base,
	];

	assert_same(['b'], array_column(Overlap::conflicts($base, $others), 'id'), 'angrenzende Termine überschneiden sich nicht');
	assert_same([], Overlap::conflicts([...$base, 'orgstatus' => 'abgesagt'], $others), 'abgesagter Termin prüft nicht');
	assert_true(Overlap::intervalsOverlap(0, 10, 5, 15));
	assert_false(Overlap::intervalsOverlap(0, 10, 10, 20));
});

test('Moderation darf im Freigabemodus weder veröffentlichen noch bestätigen', function () {
	assert_false(Workflow::canChangePublicationStatus('moderator', true));
	assert_true(Workflow::canChangePublicationStatus('moderator', false));
	assert_true(Workflow::canChangePublicationStatus('admin', true));
	assert_false(Workflow::canChangePublicationStatus(null, false), 'Gäste nie');

	assert_false(Workflow::canSetOrgStatus('moderator', true, 'freigabe', 'bestaetigt'));
	assert_false(Workflow::canSetOrgStatus('moderator', true, 'freigabe', 'veroeffentlicht'));
	assert_true(Workflow::canSetOrgStatus('moderator', true, 'angefragt', 'freigabe'));
	assert_true(Workflow::canSetOrgStatus('moderator', true, 'bestaetigt', 'bestaetigt'), 'unverändert erlaubt');
	assert_true(Workflow::canSetOrgStatus('moderator', false, 'freigabe', 'bestaetigt'));
	assert_true(Workflow::canSetOrgStatus('admin', true, 'freigabe', 'veroeffentlicht'));
});

test('Löschen und Überschneidungen zulassen', function () {
	assert_false(Workflow::canDeleteEvent('moderator', false));
	assert_true(Workflow::canDeleteEvent('moderator', true));
	assert_true(Workflow::canDeleteEvent('admin', false));
	assert_false(Workflow::canPublishWithConflicts(1, false));
	assert_true(Workflow::canPublishWithConflicts(1, true));
	assert_true(Workflow::canPublishWithConflicts(0, false));
});
