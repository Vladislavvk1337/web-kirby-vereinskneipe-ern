<?php
/**
 * Fußbereich: Adresse, Öffnungszeiten, Kontakt, Service- und Rechtslinks.
 */
$kneipe = kneipe();
$hours  = $site->openinghours()->toStructure();
?>
  </main>
  <footer class="site-footer">
    <div class="container site-footer__grid">
      <section class="site-footer__brand" aria-labelledby="footer-name">
        <p class="site-footer__logo" id="footer-name"><?php snippet('logo-mark') ?><span><?= esc($kneipe->name()) ?></span></p>
        <p class="site-footer__claim"><?= esc($site->claim()->or('Von Erndtebrück. Für Erndtebrück. Zusammen.')) ?></p>
      </section>

      <section aria-labelledby="footer-adresse">
        <h2 id="footer-adresse">Adresse</h2>
        <address>
          <?php if ($site->venue()->isNotEmpty()): ?><?= esc($site->venue()) ?><br><?php endif ?>
          <?= $site->street()->isNotEmpty() ? esc($site->street()) : '<mark class="placeholder">[Straße und Hausnummer]</mark>' ?><br>
          <?= $site->postalcode()->isNotEmpty() ? esc($site->postalcode()) : '<mark class="placeholder">[PLZ]</mark>' ?>
          <?= esc($site->city()->or('Erndtebrück')) ?>
        </address>
        <?php if ($map = $kneipe->mapUrl()): ?>
        <p><a class="link-external" href="<?= esc($map) ?>" rel="noopener">Auf der Karte ansehen<?php snippet('icon', ['name' => 'external']) ?><span class="visually-hidden"> (OpenStreetMap, externe Website)</span></a></p>
        <?php endif ?>
      </section>

      <section aria-labelledby="footer-zeiten">
        <h2 id="footer-zeiten">Öffnungszeiten</h2>
        <?php if ($hours->count()): ?>
        <dl class="hours">
          <?php foreach ($hours as $row): ?>
          <div><dt><?= kneipe_text($row->days()->value()) ?></dt><dd><?= kneipe_text($row->time()->value()) ?><?php if ($row->note()->isNotEmpty()): ?> <span class="hours__note"><?= kneipe_text($row->note()->value()) ?></span><?php endif ?></dd></div>
          <?php endforeach ?>
        </dl>
        <?php endif ?>
        <?php if ($site->openingnote()->isNotEmpty()): ?><p><?= kneipe_text($site->openingnote()->value()) ?></p><?php endif ?>
        <p><a href="<?= url('termine') ?>">Alle Termine im Kalender</a></p>
      </section>

      <section aria-labelledby="footer-kontakt">
        <h2 id="footer-kontakt">Kontakt</h2>
        <ul class="plain-list">
          <li><?php if ($site->email()->isNotEmpty()): ?><?php snippet('icon', ['name' => 'mail']) ?><?= Html::email($site->email()->value()) ?><?php else: ?><mark class="placeholder">[E-Mail-Adresse]</mark><?php endif ?></li>
          <li><?php if ($site->phone()->isNotEmpty()): ?><?php snippet('icon', ['name' => 'phone']) ?><?= Html::tel($site->phone()->value()) ?><?php else: ?><mark class="placeholder">[Telefonnummer]</mark><?php endif ?></li>
        </ul>
        <?php if ($site->social()->toStructure()->count()): ?>
        <ul class="plain-list">
          <?php foreach ($site->social()->toStructure() as $link): ?>
          <li><a href="<?= esc($link->url()) ?>" rel="noopener"><?= esc($link->platform()) ?></a></li>
          <?php endforeach ?>
        </ul>
        <?php endif ?>
      </section>
    </div>

    <div class="container site-footer__bottom">
      <nav aria-label="Service und Rechtliches">
        <ul class="inline-list">
          <li><a href="<?= url('aktuelles') ?>">Aktuelles</a></li>
          <li><a href="<?= url('termin-anfragen') ?>">Termin anfragen</a></li>
          <li><a href="<?= url('termine.ics') ?>">Kalender abonnieren</a></li>
          <li><a href="<?= url('impressum') ?>">Impressum</a></li>
          <li><a href="<?= url('datenschutz') ?>">Datenschutz</a></li>
          <li><a href="<?= url('barrierefreiheit') ?>">Barrierefreiheit</a></li>
        </ul>
      </nav>
      <p>© <?= date('Y') ?> <?= esc($kneipe->name()) ?> · ehrenamtlich betrieben</p>
    </div>
  </footer>
</body>
</html>
