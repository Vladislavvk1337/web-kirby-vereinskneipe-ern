<?php
/**
 * Seitenkopf mit der einzigen H1 der Seite.
 *
 * @var string $title
 * @var string|null $intro
 * @var string|null $eyebrow
 * @var array|null $back ['url' => …, 'text' => …]
 */
?>
<header class="page-head">
  <div class="container">
    <?php if (!empty($back)): ?>
    <p class="page-head__back"><a href="<?= esc($back['url']) ?>"><?php snippet('icon', ['name' => 'back']) ?><?= esc($back['text']) ?></a></p>
    <?php endif ?>
    <?php if (!empty($eyebrow)): ?>
    <p class="eyebrow"><?= esc($eyebrow) ?></p>
    <?php endif ?>
    <h1><?= esc($title) ?></h1>
    <?php if (!empty($intro)): ?>
    <p class="lead"><?= kneipe_text($intro) ?></p>
    <?php endif ?>
    <?= $slot ?? '' ?>
  </div>
</header>
