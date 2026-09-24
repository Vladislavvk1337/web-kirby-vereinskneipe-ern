<?php
/**
 * @var Kirby\Cms\Page $article
 * @var int|null $level
 */
$level = $level ?? 2;
$cover = $article->cover()->toFile();
?>
<article class="article-teaser<?= $cover ? ' article-teaser--image' : '' ?>">
  <?php if ($cover): ?>
  <div class="article-teaser__image"><?php snippet('image', ['image' => $cover, 'ratio' => 3 / 2, 'sizes' => '(min-width: 48em) 40vw, 100vw']) ?></div>
  <?php endif ?>
  <div class="article-teaser__body">
    <p class="article-teaser__meta"><span class="tag"><?= $article->kind()->value() === 'rueckblick' ? 'Rückblick' : 'Meldung' ?></span> <time datetime="<?= $article->date()->toDate('Y-m-d') ?>"><?= kneipe()->formatDate($article->date()->toDate(), 'd. MMMM y') ?></time></p>
    <h<?= $level ?> class="article-teaser__title"><a href="<?= $article->url() ?>"><?= esc($article->title()) ?></a></h<?= $level ?>>
    <?php if ($article->teaser()->isNotEmpty()): ?><p><?= $article->teaser()->toSafeText() ?></p><?php endif ?>
  </div>
</article>
