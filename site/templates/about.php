<?php
/** Über die Ehrenamtskneipe */
snippet('header');
?>
<?php snippet('page-head', ['title' => $page->title()->value(), 'intro' => $page->intro()->value()]) ?>
<div class="container section about">
  <div class="prose about__text"><?= $page->text()->toSafeHtml() ?></div>
  <?php if ($page->facts()->toStructure()->count()): ?>
  <aside class="about__facts" aria-labelledby="kurz-titel">
    <h2 id="kurz-titel" class="h-small">Das Wichtigste in Kürze</h2>
    <dl class="fact-cards">
      <?php foreach ($page->facts()->toStructure() as $fact): ?>
      <div><dt><?= esc($fact->title()) ?></dt><dd><?= $fact->text()->toSafeText() ?></dd></div>
      <?php endforeach ?>
    </dl>
  </aside>
  <?php endif ?>
</div>
<?php if ($cover = $page->cover()->toFile()): ?>
<figure class="photo__figure"><?php snippet('image', ['image' => $cover, 'ratio' => 16 / 7]) ?></figure>
<?php endif ?>
<div class="container section">
  <aside class="cta-band" aria-labelledby="about-cta">
    <h2 id="about-cta">Lust, einen Abend zu gestalten?</h2>
    <p>Jede Gruppe aus Erndtebrück und Umgebung kann eine Thekenschicht übernehmen.</p>
    <p><a class="button button--primary" href="<?= url('mitmachen') ?>">Mitmachen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  </aside>
</div>
<?php snippet('footer') ?>
