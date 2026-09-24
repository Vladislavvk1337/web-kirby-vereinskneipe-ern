<?php
/** Mitmachen: Ablauf, Einzelpersonen, häufige Fragen */
snippet('header');
$free = kneipe()->freeEvents(4);
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container section">
  <section aria-labelledby="ablauf-titel">
    <h2 id="ablauf-titel">So läuft es ab</h2>
    <ol class="steps steps--four">
      <?php foreach ($page->steps()->toStructure() as $step): ?>
      <li class="steps__item">
        <h3><?= esc($step->title()) ?></h3>
        <p><?= $step->text()->toSafeText() ?></p>
      </li>
      <?php endforeach ?>
    </ol>
    <p class="join__action"><a class="button button--primary" href="<?= url('termin-anfragen') ?>">Freien Termin anfragen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
    <?php if ($free->count()): ?>
    <p class="muted">Als Nächstes frei:
      <?php foreach ($free as $i => $event): ?><?= $i === $free->keys()[0] ? '' : ', ' ?><a href="<?= $event->url() ?>"><?= kneipe()->formatDate($event->startTimestamp(), 'EEE, d. MMMM') ?></a><?php endforeach ?>
    </p>
    <?php endif ?>
  </section>

  <?php if ($page->helpers()->isNotEmpty()): ?>
  <section class="section" aria-labelledby="einzeln-titel">
    <h2 id="einzeln-titel">Einzeln helfen</h2>
    <div class="prose"><?= $page->helpers()->toSafeHtml() ?></div>
  </section>
  <?php endif ?>

  <?php if ($page->faq()->toStructure()->count()): ?>
  <section class="section" aria-labelledby="faq-titel">
    <h2 id="faq-titel">Häufige Fragen</h2>
    <div class="faq">
      <?php foreach ($page->faq()->toStructure() as $item): ?>
      <details class="faq__item">
        <summary><?= esc($item->question()) ?></summary>
        <p><?= $item->answer()->toSafeText() ?></p>
      </details>
      <?php endforeach ?>
    </div>
  </section>
  <?php endif ?>
</div>
<?php snippet('footer') ?>
