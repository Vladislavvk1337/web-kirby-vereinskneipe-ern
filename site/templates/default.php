<?php
snippet('header');
?>
<?php snippet('page-head', ['title' => $page->title()->value()]) ?>
<div class="container container--narrow section">
  <div class="prose"><?= $page->text()->toSafeHtml() ?></div>
</div>
<?php snippet('footer') ?>
