<?php
/** Bestätigung nach dem Absenden (Post/Redirect/Get), nicht indexiert */
snippet('header', ['noindex' => true]);
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'eyebrow' => 'Anfrage gesendet']) ?>
<div class="container container--narrow section">
  <div class="notice notice--success" role="status"><?php snippet('icon', ['name' => 'check']) ?><p><?= $page->text()->toSafeText() ?></p></div>
  <h2>Wie geht es weiter?</h2>
  <ol class="plain-steps">
    <li>Die Redaktion prüft eure Anfrage.</li>
    <li>Wir melden uns per E-Mail oder Telefon bei euch.</li>
    <li>Wenn alles passt, erscheint der Abend im Kalender – mit eurem Namen, wenn ihr zustimmt.</li>
  </ol>
  <p><a class="link-arrow" href="<?= url('termine') ?>">Zurück zum Kalender<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
</div>
<?php snippet('footer') ?>
