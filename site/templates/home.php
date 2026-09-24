<?php
/**
 * Startseite: Was ist das? Wann ist geöffnet? Wer steht hinter der
 * Theke? Was ist frei? Wie kann man mitmachen? Wo ist das?
 *
 * @var EventPage|null $next
 * @var TeamPage|null $nextTeam
 * @var Kirby\Cms\Pages $upcoming
 * @var Kirby\Cms\Pages $free
 * @var Kirby\Cms\Page|null $article
 */
snippet('header');
$kneipe = kneipe();
?>
<section class="hero" aria-labelledby="hero-titel">
  <div class="container hero__inner">
    <p class="eyebrow"><?= esc($kneipe->name()) ?></p>
    <h1 id="hero-titel" class="hero__title"><?= esc($site->claim()->or('Von Erndtebrück. Für Erndtebrück. Zusammen.')) ?></h1>
    <p class="lead hero__lead"><?= kneipe_text($page->intro()->or($site->description())->value()) ?></p>
    <p class="hero__actions">
      <a class="button button--primary" href="<?= $next ? $next->url() : url('termine') ?>">Nächsten Termin ansehen<?php snippet('icon', ['name' => 'arrow']) ?></a>
    </p>
  </div>
  <?php snippet('landscape') ?>
</section>

<section class="next" id="naechster-termin" aria-labelledby="next-titel">
  <div class="container">
    <div class="next__card">
      <h2 id="next-titel" class="next__label">Nächster Öffnungstermin</h2>
      <?php if ($next): ?>
      <p class="next__date">
        <time datetime="<?= date('c', $next->startTimestamp()) ?>">
          <span class="next__weekday"><?= $kneipe->formatDate($next->startTimestamp(), 'EEEE') ?></span>
          <span class="next__day"><?= $kneipe->formatDate($next->startTimestamp(), 'd. MMMM') ?></span>
        </time>
      </p>
      <div class="next__details">
        <p class="next__title"><a href="<?= $next->url() ?>"><?= esc($next->publicTitle()) ?></a></p>
        <ul class="facts">
          <li><?php snippet('icon', ['name' => 'clock']) ?><?= esc($kneipe->timeRange($next)) ?></li>
          <?php if ($doors = $next->doorsTimestamp()): ?><li><?php snippet('icon', ['name' => 'door']) ?>Einlass <?= date('H:i', $doors) ?> Uhr</li><?php endif ?>
          <?php if ($team = $next->publicTeam()): ?><li><?php snippet('icon', ['name' => 'users']) ?>Hinter der Theke: <?= esc($team->title()) ?></li><?php endif ?>
          <li><?php snippet('status', ['event' => $next]) ?></li>
        </ul>
      </div>
      <?php else: ?>
      <?php snippet('empty-state', [
        'title'    => 'Gerade steht kein Öffnungstermin fest.',
        'text'     => 'Schaut bald wieder vorbei – oder übernehmt mit eurer Gruppe selbst einen Abend.',
        'linkUrl'  => url('termin-anfragen'),
        'linkText' => 'Termin anfragen',
      ]) ?>
      <?php endif ?>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="upcoming-titel">
  <div class="container">
    <div class="section__head">
      <h2 id="upcoming-titel">Die nächsten Termine</h2>
      <a class="link-arrow" href="<?= url('termine') ?>">Alle Termine<?php snippet('icon', ['name' => 'arrow']) ?></a>
    </div>
    <?php if ($upcoming->count()): ?>
    <ul class="event-list">
      <?php foreach ($upcoming as $event): ?>
      <?php snippet('event-item', ['event' => $event]) ?>
      <?php endforeach ?>
    </ul>
    <?php else: ?>
    <?php snippet('empty-state', ['title' => 'Noch keine Termine veröffentlicht.', 'text' => 'Sobald die nächsten Abende feststehen, erscheinen sie hier.']) ?>
    <?php endif ?>
  </div>
</section>

<?php if ($nextTeam): ?>
<section class="section section--team" aria-labelledby="team-titel">
  <div class="container team-feature">
    <div class="team-feature__logo">
      <?php if ($logo = $nextTeam->logo()->toFile()): ?>
      <?php snippet('image', ['image' => $logo, 'width' => 160, 'height' => 160]) ?>
      <?php else: ?>
      <?php snippet('icon', ['name' => 'users']) ?>
      <?php endif ?>
    </div>
    <div class="team-feature__text">
      <h2 id="team-titel" class="eyebrow-heading"><span class="eyebrow">Das nächste Thekenteam</span><?= esc($nextTeam->title()) ?></h2>
      <p class="team-feature__type"><?= esc($nextTeam->typeLabel()) ?></p>
      <?php if ($nextTeam->teaser()->isNotEmpty()): ?><p><?= $nextTeam->teaser()->toSafeText() ?></p><?php endif ?>
      <p><a class="link-arrow" href="<?= $nextTeam->url() ?>">Das Team kennenlernen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
    </div>
  </div>
</section>
<?php endif ?>

<section class="section section--free" aria-labelledby="free-titel">
  <div class="container free">
    <div class="free__text">
      <h2 id="free-titel">Noch freie Termine</h2>
      <p>Diese Abende sind noch zu haben. Eure Gruppe kann einen davon übernehmen – ein Benutzerkonto braucht ihr nicht.</p>
      <p><a class="button button--primary" href="<?= url('termin-anfragen') ?>">Termin anfragen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
    </div>
    <?php if ($free->count()): ?>
    <ul class="free__list">
      <?php foreach ($free as $event): ?>
      <li><a class="free__slot" href="<?= $event->url() ?>">
        <?php snippet('icon', ['name' => 'free']) ?>
        <span><strong><?= $kneipe->formatDate($event->startTimestamp(), 'EEE, d. MMMM') ?></strong><span class="free__time"><?= esc($kneipe->timeRange($event)) ?> · Termin frei</span></span>
      </a></li>
      <?php endforeach ?>
    </ul>
    <?php else: ?>
    <?php snippet('empty-state', ['title' => 'Aktuell sind keine Termine ausgeschrieben.', 'text' => 'Ihr könnt trotzdem ein Wunschdatum vorschlagen – wir melden uns.']) ?>
    <?php endif ?>
  </div>
</section>

<section class="section" aria-labelledby="model-titel">
  <div class="container">
    <h2 id="model-titel"><?= esc($page->modelheadline()->or('Gemeinsam einen Abend gestalten')) ?></h2>
    <ol class="steps">
      <?php foreach ($page->modelsteps()->toStructure() as $step): ?>
      <li class="steps__item">
        <h3><?= esc($step->title()) ?></h3>
        <p><?= $step->text()->toSafeText() ?></p>
      </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>

<?php if ($photo = $page->photo()->toFile()): ?>
<section class="photo" aria-label="Bild aus der Kneipe">
  <figure class="photo__figure">
    <?php snippet('image', ['image' => $photo, 'ratio' => 16 / 7, 'sizes' => '100vw', 'class' => 'photo__image']) ?>
    <?php if ($page->photocaption()->isNotEmpty()): ?>
    <figcaption class="container photo__caption"><?= $page->photocaption()->toSafeText() ?></figcaption>
    <?php endif ?>
  </figure>
</section>
<?php endif ?>

<section class="section section--join" aria-labelledby="join-titel">
  <div class="container join">
    <h2 id="join-titel"><?= esc($page->joinheadline()->or('Ihr möchtet eine Thekenschicht übernehmen?')) ?></h2>
    <p class="lead"><?= $page->jointext()->toSafeText() ?></p>
    <p><a class="button button--primary" href="<?= url('mitmachen') ?>">So könnt ihr mitmachen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </div>
</section>

<?php if ($article): ?>
<section class="section" aria-labelledby="news-titel">
  <div class="container">
    <div class="section__head">
      <h2 id="news-titel">Aktuelles</h2>
      <a class="link-arrow" href="<?= url('aktuelles') ?>">Alle Meldungen und Rückblicke<?php snippet('icon', ['name' => 'arrow']) ?></a>
    </div>
    <?php snippet('article-teaser', ['article' => $article, 'level' => 3]) ?>
  </div>
</section>
<?php endif ?>

<?php snippet('footer') ?>
