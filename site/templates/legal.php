<?php
/** Impressum, Datenschutz, Barrierefreiheit – KirbyText nur von der Administration */
snippet('header');
?>
<?php snippet('page-head', ['title' => $page->title()->value()]) ?>
<div class="container container--narrow section">
  <?php if ($page->draftnote()->toBool(true)): ?>
  <div class="notice notice--warning" role="note"><?php snippet('icon', ['name' => 'alert']) ?><p><strong>Entwurf:</strong> Dieser Text ist noch nicht rechtlich geprüft. Markierte Stellen müssen vor dem Livegang ergänzt werden.</p></div>
  <?php endif ?>
  <div class="prose"><?= $page->text()->kirbytext() ?></div>
  <?php if ($page->reviewed()->isNotEmpty()): ?>
  <p class="muted small">Zuletzt geprüft: <?= kneipe()->formatDate($page->reviewed()->toDate(), 'd. MMMM y') ?></p>
  <?php endif ?>
</div>
<?php snippet('footer') ?>
