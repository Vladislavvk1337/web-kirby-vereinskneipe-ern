<?php
/**
 * Termin-Detailseite. Gibt nur öffentliche Felder aus – interne Notiz,
 * verantwortliche Person, Protokoll und Überschneidungsdaten nie.
 *
 * @var EventPage $page
 */
snippet('header');
$kneipe = kneipe();
$start  = $page->startTimestamp();
$cover  = $page->cover()->toFile();
$private = $page->isPrivateEvent();
?>
<article class="event-detail">
  <?php snippet('page-head', [
    'title'   => $page->publicTitle(),
    'eyebrow' => $private ? null : $page->eventTypeLabel(),
    'back'    => ['url' => url('termine'), 'text' => 'Alle Termine'],
  ], slots: true) ?>
    <p class="event-detail__status"><?php snippet('status', ['event' => $page]) ?></p>
  <?php endsnippet() ?>

  <div class="container event-detail__grid">
    <div class="event-detail__main">
      <?php if ($page->isCancelled()): ?>
      <div class="notice notice--danger" role="note"><?php snippet('icon', ['name' => 'cancel']) ?><p><strong>Diese Veranstaltung ist abgesagt.</strong> Bitte kommt an diesem Termin nicht vorbei.</p></div>
      <?php elseif ($page->isPast()): ?>
      <div class="notice" role="note"><?php snippet('icon', ['name' => 'info']) ?><p>Dieser Termin liegt in der Vergangenheit.</p></div>
      <?php endif ?>

      <?php if ($page->isFree() && !$page->isPast()): ?>
      <div class="free-callout">
        <h2>Dieser Termin ist noch frei.</h2>
        <p>Eure Gruppe möchte an diesem Abend hinter der Theke stehen? Fragt den Termin unverbindlich an.</p>
        <p><a class="button button--primary" href="<?= url('termin-anfragen') ?>?termin=<?= $page->slug() ?>">Diesen Termin anfragen<?php snippet('icon', ['name' => 'arrow']) ?></a></p>
      </div>
      <?php endif ?>

      <?php if ($cover && !$private): ?>
      <figure class="event-detail__cover">
        <?php snippet('image', ['image' => $cover, 'ratio' => 3 / 2, 'lazy' => false, 'sizes' => '(min-width: 64em) 60vw, 100vw']) ?>
        <?php if ($cover->caption()->isNotEmpty() || $cover->credit()->isNotEmpty()): ?>
        <figcaption><?= kneipe_text(trim($cover->caption() . ' ' . ($cover->credit()->isNotEmpty() ? '· ' . $cover->credit() : ''))) ?></figcaption>
        <?php endif ?>
      </figure>
      <?php endif ?>

      <?php if ($page->publicTeaser() !== ''): ?>
      <p class="lead"><?= kneipe_text($page->publicTeaser()) ?></p>
      <?php endif ?>

      <?php if (!$private && $page->text()->isNotEmpty()): ?>
      <div class="prose"><?= $page->text()->toSafeHtml() ?></div>
      <?php endif ?>

      <?php if (!$private && $page->gallery()->toFiles()->count()): ?>
      <section class="gallery" aria-labelledby="galerie-titel">
        <h2 id="galerie-titel">Bilder</h2>
        <ul class="gallery__list">
          <?php foreach ($page->gallery()->toFiles() as $image): ?>
          <li><?php snippet('image', ['image' => $image, 'ratio' => 1, 'sizes' => '(min-width: 48em) 25vw, 50vw']) ?></li>
          <?php endforeach ?>
        </ul>
      </section>
      <?php endif ?>
    </div>

    <aside class="event-detail__facts" aria-labelledby="fakten-titel">
      <h2 id="fakten-titel" class="visually-hidden">Das Wichtigste auf einen Blick</h2>
      <dl class="fact-list">
        <?php if ($start): ?>
        <div><dt><?php snippet('icon', ['name' => 'calendar']) ?>Datum</dt><dd><time datetime="<?= date($page->isAllDay() ? 'Y-m-d' : 'c', $start) ?>"><?= $kneipe->formatDate($start) ?></time></dd></div>
        <div><dt><?php snippet('icon', ['name' => 'clock']) ?>Uhrzeit</dt><dd><?= esc($kneipe->timeRange($page)) ?></dd></div>
        <?php endif ?>
        <?php if ($doors = $page->doorsTimestamp()): ?>
        <div><dt><?php snippet('icon', ['name' => 'door']) ?>Einlass</dt><dd><?= date('H:i', $doors) ?> Uhr</dd></div>
        <?php endif ?>
        <div><dt><?php snippet('icon', ['name' => 'pin']) ?>Ort</dt><dd>
          <?php if ($page->locationName() !== ''): ?><?= esc($page->locationName()) ?><br><?php endif ?>
          <?= $page->locationAddress() !== '' ? kneipe_text($page->locationAddress()) : '<mark class="placeholder">[Adresse folgt]</mark>' ?>
          <?php if ($map = $kneipe->mapUrl()): ?><br><a class="link-external" href="<?= esc($map) ?>" rel="noopener">Karte<?php snippet('icon', ['name' => 'external']) ?><span class="visually-hidden"> (OpenStreetMap, externe Website)</span></a><?php endif ?>
        </dd></div>
        <?php if ($team): ?>
        <div><dt><?php snippet('icon', ['name' => 'users']) ?>Thekenteam</dt><dd><a href="<?= $team->url() ?>"><?= esc($team->title()) ?></a></dd></div>
        <?php endif ?>
        <?php if (!$private && $page->audience()->isNotEmpty()): ?>
        <div><dt><?php snippet('icon', ['name' => 'users']) ?>Für wen?</dt><dd><?= $page->audience()->toSafeText() ?></dd></div>
        <?php endif ?>
        <?php if (!$private && $page->admission()->isNotEmpty()): ?>
        <div><dt><?php snippet('icon', ['name' => 'ticket']) ?>Eintritt</dt><dd><?= $page->admission()->toSafeText() ?></dd></div>
        <?php endif ?>
        <?php if (!$private && $page->publiccontact()->isNotEmpty()): ?>
        <div><dt><?php snippet('icon', ['name' => 'mail']) ?>Kontakt</dt><dd><?= $page->publiccontact()->toSafeText() ?></dd></div>
        <?php endif ?>
      </dl>
      <?php if ($page->hasIcs() && !$page->isPast()): ?>
      <p><a class="button button--secondary button--block" href="<?= $page->url() ?>.ics" download><?php snippet('icon', ['name' => 'download']) ?>In den eigenen Kalender eintragen</a></p>
      <?php endif ?>
    </aside>
  </div>
</article>
<?php snippet('footer') ?>
