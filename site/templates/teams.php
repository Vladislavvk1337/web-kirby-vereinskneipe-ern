<?php
/** Übersicht der Thekenteams – nur Teams mit Einwilligung */
snippet('header');
$teams = $page->children()->listed()->filter(fn ($team) => $team->isPublic())->sortBy('title', 'asc');
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container section">
  <?php if ($teams->count()): ?>
  <ul class="team-grid">
    <?php foreach ($teams as $team): ?>
    <li class="team-card">
      <div class="team-card__logo">
        <?php if ($logo = $team->logo()->toFile()): ?>
        <?php snippet('image', ['image' => $logo, 'width' => 96, 'height' => 96]) ?>
        <?php else: ?>
        <?php snippet('icon', ['name' => 'users']) ?>
        <?php endif ?>
      </div>
      <h2 class="team-card__title"><a href="<?= $team->url() ?>"><?= esc($team->title()) ?></a></h2>
      <p class="team-card__type"><?= esc($team->typeLabel()) ?></p>
      <?php if ($team->teaser()->isNotEmpty()): ?><p><?= $team->teaser()->toSafeText() ?></p><?php endif ?>
      <?php if ($next = $team->upcomingEvents()->first()): ?>
      <p class="team-card__next"><?php snippet('icon', ['name' => 'calendar']) ?>Nächster Abend: <?= kneipe()->formatDate($next->startTimestamp(), 'd. MMMM') ?></p>
      <?php endif ?>
    </li>
    <?php endforeach ?>
  </ul>
  <?php else: ?>
  <?php snippet('empty-state', ['title' => 'Hier stellen sich bald die Thekenteams vor.', 'text' => 'Eure Gruppe könnte die erste sein.', 'linkUrl' => url('mitmachen'), 'linkText' => 'So könnt ihr mitmachen']) ?>
  <?php endif ?>

  <aside class="cta-band" aria-labelledby="teams-cta">
    <h2 id="teams-cta">Eure Gruppe fehlt noch?</h2>
    <p>Vereine, Initiativen, Unternehmen und private Gruppen können einen Abend übernehmen.</p>
    <p><a class="button button--primary" href="<?= url('termin-anfragen') ?>">Termin anfragen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </aside>
</div>
<?php snippet('footer') ?>
