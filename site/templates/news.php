<?php
/** Aktuelles und Rückblicke */
snippet('header');
$articles = $page->children()->listed()->sortBy('date', 'desc')->paginate(10);
$past     = kneipe()->pastEvents(3);
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container section news">
  <div class="news__list">
    <?php if ($articles->count()): ?>
      <?php foreach ($articles as $article): ?>
      <?php snippet('article-teaser', ['article' => $article]) ?>
      <?php endforeach ?>
      <?php if ($articles->pagination()->hasPages()): ?>
      <nav class="pagination" aria-label="Weitere Beiträge">
        <?php if ($articles->pagination()->hasPrevPage()): ?><a href="<?= $articles->pagination()->prevPageUrl() ?>">Neuere Beiträge</a><?php endif ?>
        <?php if ($articles->pagination()->hasNextPage()): ?><a href="<?= $articles->pagination()->nextPageUrl() ?>">Ältere Beiträge</a><?php endif ?>
      </nav>
      <?php endif ?>
    <?php else: ?>
      <?php snippet('empty-state', ['title' => 'Noch keine Meldungen.', 'text' => 'Neuigkeiten und Rückblicke erscheinen hier, sobald die Redaktion sie veröffentlicht.']) ?>
    <?php endif ?>
  </div>
  <?php if ($past->count()): ?>
  <aside class="news__aside" aria-labelledby="zuletzt-titel">
    <h2 id="zuletzt-titel" class="h-small">Zuletzt in der Kneipe</h2>
    <ul class="event-list event-list--compact">
      <?php foreach ($past as $event): ?><?php snippet('event-item', ['event' => $event]) ?><?php endforeach ?>
    </ul>
    <p><a class="link-arrow" href="<?= url('termine') ?>?zeitraum=vergangen#kalender">Alle vergangenen Termine<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </aside>
  <?php endif ?>
</div>
<?php snippet('footer') ?>
