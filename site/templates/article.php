<?php
/** Meldung oder Rückblick */
snippet('header');
$cover = $page->cover()->toFile();
$event = $page->event()->toPage();
?>
<article>
  <?php snippet('page-head', [
    'title'   => $page->title()->value(),
    'eyebrow' => ($page->kind()->value() === 'rueckblick' ? 'Rückblick' : 'Meldung') . ' · ' . kneipe()->formatDate($page->date()->toDate(), 'd. MMMM y'),
    'back'    => ['url' => url('aktuelles'), 'text' => 'Aktuelles'],
  ]) ?>
  <div class="container container--narrow section">
    <?php if ($page->teaser()->isNotEmpty()): ?><p class="lead"><?= $page->teaser()->toSafeText() ?></p><?php endif ?>
    <?php if ($cover): ?>
    <figure class="article__cover">
      <?php snippet('image', ['image' => $cover, 'ratio' => 3 / 2, 'lazy' => false, 'sizes' => '(min-width: 48em) 46rem, 100vw']) ?>
      <?php if ($cover->caption()->isNotEmpty()): ?><figcaption><?= $cover->caption()->toSafeText() ?></figcaption><?php endif ?>
    </figure>
    <?php endif ?>
    <div class="prose"><?= $page->text()->toSafeHtml() ?></div>
    <?php if ($event && $event->isPublic()): ?>
    <p class="article__event"><?php snippet('icon', ['name' => 'calendar']) ?>Zum Termin: <a href="<?= $event->url() ?>"><?= esc($event->publicTitle()) ?></a></p>
    <?php endif ?>
    <?php if ($page->gallery()->toFiles()->count()): ?>
    <section class="gallery" aria-labelledby="artikel-bilder">
      <h2 id="artikel-bilder">Bilder</h2>
      <ul class="gallery__list">
        <?php foreach ($page->gallery()->toFiles() as $image): ?>
        <li><?php snippet('image', ['image' => $image, 'ratio' => 1, 'sizes' => '(min-width: 48em) 25vw, 50vw']) ?></li>
        <?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
  </div>
</article>
<?php snippet('footer') ?>
