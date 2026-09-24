Neue Terminanfrage über die Website <?= $siteName ?>


Gruppe:        <?= $values['groupname'] ?>

Wunschtermin:  <?= $request->slotlabel() ?>

<?php if ($values['altdate']): ?>
Alternative:   <?= date('d.m.Y', strtotime($values['altdate'])) ?>

<?php endif ?>

Alle Angaben stehen im Redaktionsbereich:
<?= $panelUrl ?>


Auf diese E-Mail antworten erreicht die anfragende Person direkt.
Bitte keine Daten aus der Anfrage an Dritte weitergeben. Die Anfrage wird
nach Ablauf der Löschfrist automatisch gelöscht.
