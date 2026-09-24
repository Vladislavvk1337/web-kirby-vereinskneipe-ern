<?php
/**
 * Gestalteter Leerzustand statt technischer Meldung.
 *
 * @var string $title
 * @var string $text
 * @var string|null $linkUrl
 * @var string|null $linkText
 */
?>
<div class="empty-state">
  <?php snippet('icon', ['name' => 'calendar', 'class' => 'empty-state__icon']) ?>
  <p class="empty-state__title"><?= esc($title) ?></p>
  <p><?= esc($text) ?></p>
  <?php if (!empty($linkUrl)): ?>
  <p><a class="link-arrow" href="<?= esc($linkUrl) ?>"><?= esc($linkText ?? 'Weiter') ?><?php snippet('icon', ['name' => 'arrow']) ?></a></p>
  <?php endif ?>
</div>
