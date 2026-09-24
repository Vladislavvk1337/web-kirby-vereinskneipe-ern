<?php
/**
 * Thekenteam-Detailseite. Interne Kontaktdaten (contactperson,
 * contactemail, contactphone, internalnote, consentnote) werden hier
 * bewusst nicht verwendet.
 *
 * @var TeamPage $page
 */
snippet('header');
$cover = $page->cover()->toFile();
?>
<article>
  <?php snippet('page-head', [
    'title'   => $page->title()->value(),
    'eyebrow' => $page->typeLabel(),
    'back'    => ['url' => url('thekenteams'), 'text' => 'Alle Thekenteams'],
  ]) ?>
  <div class="container team-detail">
    <div class="team-detail__main">
      <?php if ($page->teaser()->isNotEmpty()): ?><p class="lead"><?= $page->teaser()->toSafeText() ?></p><?php endif ?>
      <?php if ($cover): ?>
      <figure class="team-detail__cover"><?php snippet('image', ['image' => $cover, 'ratio' => 3 / 2, 'lazy' => false, 'sizes' => '(min-width: 64em) 60vw, 100vw']) ?></figure>
      <?php endif ?>
      <?php if ($page->text()->isNotEmpty()): ?><div class="prose"><?= $page->text()->toSafeHtml() ?></div><?php endif ?>

      <section class="team-detail__events" aria-labelledby="team-kommend">
        <h2 id="team-kommend">Kommende Termine</h2>
        <?php if ($upcoming->count()): ?>
        <ul class="event-list">
          <?php foreach ($upcoming as $event): ?><?php snippet('event-item', ['event' => $event]) ?><?php endforeach ?>
        </ul>
        <?php else: ?>
        <p class="muted">Gerade ist kein Termin mit diesem Team geplant.</p>
        <?php endif ?>
      </section>

      <?php if ($past->count()): ?>
      <section class="team-detail__events" aria-labelledby="team-vergangen">
        <h2 id="team-vergangen">Vergangene Termine</h2>
        <ul class="event-list event-list--compact">
          <?php foreach ($past as $event): ?><?php snippet('event-item', ['event' => $event]) ?><?php endforeach ?>
        </ul>
      </section>
      <?php endif ?>

      <?php if ($page->gallery()->toFiles()->count()): ?>
      <section class="gallery" aria-labelledby="team-bilder">
        <h2 id="team-bilder">Bilder</h2>
        <ul class="gallery__list">
          <?php foreach ($page->gallery()->toFiles() as $image): ?>
          <li><?php snippet('image', ['image' => $image, 'ratio' => 1, 'sizes' => '(min-width: 48em) 25vw, 50vw']) ?></li>
          <?php endforeach ?>
        </ul>
      </section>
      <?php endif ?>
    </div>
    <aside class="team-detail__side">
      <?php if ($logo = $page->logo()->toFile()): ?>
      <div class="team-detail__logo"><?php snippet('image', ['image' => $logo, 'width' => 200, 'height' => 200]) ?></div>
      <?php endif ?>
      <?php if ($links = $page->publicLinks()): ?>
      <h2 class="h-small">Mehr über das Team</h2>
      <ul class="plain-list">
        <?php foreach ($links as $link): ?>
        <li><a class="link-external" href="<?= esc($link['url']) ?>" rel="noopener"><?= esc($link['label']) ?><?php snippet('icon', ['name' => 'external']) ?><span class="visually-hidden"> (externe Website)</span></a></li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
    </aside>
  </div>
</article>
<?php snippet('footer') ?>
