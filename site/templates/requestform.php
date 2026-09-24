<?php
/**
 * Formular „Freien Termin anfragen“ – funktioniert ohne JavaScript.
 * Fehler stehen oben als Übersicht und direkt am jeweiligen Feld.
 *
 * @var array $freeSlots Slug => Beschriftung
 * @var array $values
 * @var array $errors
 * @var string|null $notice
 */
use Kneipe\RequestForm;
use Kneipe\RequestValidator;

snippet('header');

$labels = [
	'slot'        => 'Gewünschter Termin',
	'wishdate'    => 'Eigenes Wunschdatum',
	'altdate'     => 'Alternativer Termin',
	'groupname'   => 'Name der Gruppe',
	'grouptype'   => 'Art der Gruppe',
	'contactname' => 'Name der Kontaktperson',
	'email'       => 'E-Mail-Adresse',
	'phone'       => 'Telefonnummer',
	'intro'       => 'Kurze Vorstellung',
	'message'     => 'Nachricht',
	'privacy'     => 'Datenschutz',
];
$field = fn (string $name, array $options = []) => snippet('form/field', [
	'name'   => $name,
	'label'  => $labels[$name],
	'values' => $values,
	'errors' => $errors,
	...$options,
]);
$selected = $values['slot'] ?? '';
$tomorrow = date('Y-m-d', strtotime('+1 day'));
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container container--narrow section">
  <?php if ($notice): ?>
  <div class="notice notice--danger" role="alert" id="formular-hinweis" tabindex="-1"><?php snippet('icon', ['name' => 'alert']) ?><p><?= esc($notice) ?></p></div>
  <?php endif ?>

  <?php if ($errors): ?>
  <div class="error-summary" role="alert" id="fehleruebersicht" tabindex="-1" aria-labelledby="fehleruebersicht-titel">
    <h2 id="fehleruebersicht-titel">Bitte prüft noch <?= count($errors) === 1 ? 'eine Angabe' : count($errors) . ' Angaben' ?>:</h2>
    <ul>
      <?php foreach ($errors as $name => $message): ?>
      <li><a href="#feld-<?= $name === 'slot' ? 'slot-' . ($freeSlots ? array_key_first($freeSlots) : RequestValidator::OTHER) : $name ?>"><?= esc($labels[$name] ?? $name) ?>: <?= esc($message) ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
  <?php endif ?>

  <form class="form" method="post" action="<?= $page->url() ?>" novalidate>
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
    <input type="hidden" name="<?= RequestForm::TIMER ?>" value="<?= esc($timer) ?>">
    <div class="form__hp" aria-hidden="true">
      <label for="feld-website">Website – dieses Feld bitte leer lassen</label>
      <input type="text" id="feld-website" name="<?= RequestForm::HONEYPOT ?>" value="" tabindex="-1" autocomplete="off">
    </div>

    <fieldset class="form__group<?= isset($errors['slot']) ? ' field--error' : '' ?>" aria-describedby="slot-hinweis<?= isset($errors['slot']) ? ' feld-slot-fehler' : '' ?>">
      <legend>Wunschtermin</legend>
      <p class="field__hint" id="slot-hinweis">Wählt einen freien Termin aus. Passt keiner, schlagt ein eigenes Datum vor.</p>
      <?php if (isset($errors['slot'])): ?><p class="field__error" id="feld-slot-fehler"><?php snippet('icon', ['name' => 'alert']) ?><span><span class="visually-hidden">Fehler: </span><?= esc($errors['slot']) ?></span></p><?php endif ?>
      <div class="choices">
        <?php foreach ($freeSlots as $slug => $label): ?>
        <div class="choice">
          <input type="radio" id="feld-slot-<?= esc($slug) ?>" name="slot" value="<?= esc($slug) ?>"<?= $selected === $slug ? ' checked' : '' ?>>
          <label for="feld-slot-<?= esc($slug) ?>"><?php snippet('icon', ['name' => 'free']) ?><span><?= esc($label) ?></span></label>
        </div>
        <?php endforeach ?>
        <div class="choice">
          <input type="radio" id="feld-slot-<?= RequestValidator::OTHER ?>" name="slot" value="<?= RequestValidator::OTHER ?>"<?= $selected === RequestValidator::OTHER || (!$freeSlots && $selected === '') ? ' checked' : '' ?>>
          <label for="feld-slot-<?= RequestValidator::OTHER ?>"><?php snippet('icon', ['name' => 'calendar']) ?><span>Anderes Datum vorschlagen</span></label>
        </div>
      </div>
      <?php if (!$freeSlots): ?>
      <p class="muted">Gerade sind keine Termine ausgeschrieben – schlagt einfach ein Datum vor.</p>
      <?php endif ?>
      <div class="form__row">
        <?php $field('wishdate', ['type' => 'date', 'hint' => 'Nur bei „Anderes Datum vorschlagen“.', 'min' => $tomorrow]) ?>
        <?php $field('altdate', ['type' => 'date', 'hint' => 'Falls der Wunschtermin nicht klappt.', 'min' => $tomorrow]) ?>
      </div>
    </fieldset>

    <fieldset class="form__group">
      <legend>Eure Gruppe</legend>
      <?php $field('groupname', ['required' => true, 'autocomplete' => 'organization', 'maxlength' => 120]) ?>
      <?php $field('grouptype', ['type' => 'select', 'required' => true, 'options' => RequestValidator::GROUP_TYPES]) ?>
      <?php $field('intro', ['type' => 'textarea', 'required' => true, 'rows' => 4, 'hint' => 'Wer seid ihr und was macht ihr? Ein, zwei Sätze reichen.', 'maxlength' => 1500]) ?>
    </fieldset>

    <fieldset class="form__group">
      <legend>Ansprechperson</legend>
      <?php $field('contactname', ['required' => true, 'autocomplete' => 'name', 'maxlength' => 100]) ?>
      <?php $field('email', ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'hint' => 'Hierhin schicken wir unsere Antwort.', 'maxlength' => 254]) ?>
      <?php $field('phone', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'hint' => 'Falls wir kurz anrufen dürfen.', 'maxlength' => 40]) ?>
    </fieldset>

    <fieldset class="form__group">
      <legend>Noch etwas?</legend>
      <?php $field('message', ['type' => 'textarea', 'rows' => 5, 'hint' => 'Ideen für den Abend, Fragen oder besondere Wünsche.', 'maxlength' => 3000]) ?>
    </fieldset>

    <div class="field field--check<?= isset($errors['privacy']) ? ' field--error' : '' ?>">
      <?php if (isset($errors['privacy'])): ?><p class="field__error" id="feld-privacy-fehler"><?php snippet('icon', ['name' => 'alert']) ?><span><span class="visually-hidden">Fehler: </span><?= esc($errors['privacy']) ?></span></p><?php endif ?>
      <div class="choice choice--check">
        <input type="checkbox" id="feld-privacy" name="privacy" value="ja" required<?= ($values['privacy'] ?? '') === 'ja' ? ' checked' : '' ?><?= isset($errors['privacy']) ? ' aria-invalid="true" aria-describedby="feld-privacy-fehler"' : '' ?>>
        <label for="feld-privacy">Ich habe die <a href="<?= url('datenschutz') ?>">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben zur Bearbeitung der Anfrage gespeichert werden.</label>
      </div>
      <?php if ($page->privacynote()->isNotEmpty()): ?><p class="field__hint"><?= $page->privacynote()->toSafeText() ?> Löschfrist: <?= kneipe()->retentionDays() ?> Tage.</p><?php endif ?>
    </div>

    <div class="form__submit">
      <button class="button button--primary button--large" type="submit">Anfrage senden<?php snippet('icon', ['name' => 'arrow']) ?></button>
      <p class="muted small">Die Anfrage ist unverbindlich. Sie wird nicht veröffentlicht.</p>
    </div>
  </form>
</div>
<?php snippet('footer') ?>
