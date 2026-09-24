<?php
/**
 * <head>: Titel, Beschreibung, Canonical, Open Graph, Favicons, lokale
 * Schriften und CSS, strukturierte Daten.
 *
 * Optionale Variablen (aus Controller/Template):
 *   $metaTitle, $metaDescription, $noindex (bool), $canonical,
 *   $ogImage (File|null), $styles (array), $jsonld (array)
 */
$kneipe   = kneipe();
$name     = $kneipe->name();
$isHome   = $page->isHomePage();
$baseTitle = $metaTitle ?? $page->seotitle()->or($page->title())->value();
$fullTitle = $isHome
	? $name . ' – ' . $site->claim()->or('Von Erndtebrück. Für Erndtebrück. Zusammen.')
	: $baseTitle . ' – ' . $name;

$description = $metaDescription
	?? $page->seodescription()->or($page->teaser())->or($page->intro())->or($site->description())->value();
$description = Str::short(trim(preg_replace('/\s+/', ' ', strip_tags((string)$description))), 160);

$canonical = $canonical ?? $page->url();
$robots    = ($noindex ?? false) || $page->noindex()->toBool() || $kneipe->noindex();

$ogFile = $ogImage
	?? $page->ogimage()->toFile()
	?? $page->cover()->toFile()
	?? $site->ogimage()->toFile();
$ogUrl  = $ogFile
	? $ogFile->thumb(['width' => 1200, 'height' => 630, 'crop' => true, 'format' => 'jpg'])->url()
	: url('assets/brand/og-default.png');

$styles = ['tokens', 'site', ...($styles ?? [])];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($fullTitle) ?></title>
  <meta name="description" content="<?= esc($description) ?>">
  <link rel="canonical" href="<?= esc($canonical) ?>">
  <?php if ($robots): ?>
  <meta name="robots" content="noindex, follow">
  <?php endif ?>

  <meta property="og:type" content="<?= $page->intendedTemplate()->name() === 'article' ? 'article' : 'website' ?>">
  <meta property="og:locale" content="de_DE">
  <meta property="og:site_name" content="<?= esc($name) ?>">
  <meta property="og:title" content="<?= esc($isHome ? $name : $baseTitle) ?>">
  <meta property="og:description" content="<?= esc($description) ?>">
  <meta property="og:url" content="<?= esc($canonical) ?>">
  <meta property="og:image" content="<?= esc($ogUrl) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">

  <meta name="theme-color" content="#17384f">
  <link rel="icon" href="<?= url('assets/brand/favicon.svg') ?>" type="image/svg+xml">
  <link rel="icon" href="<?= url('assets/brand/favicon-32.png') ?>" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="<?= url('assets/brand/apple-touch-icon.png') ?>">
  <link rel="alternate" type="text/calendar" title="Termine als Kalender (iCalendar)" href="<?= url('termine.ics') ?>">

  <link rel="preload" href="<?= url('assets/fonts/atkinson-hyperlegible-next-latin-var.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= url('assets/fonts/fraunces-latin-var.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <?php foreach ($styles as $style): ?>
  <link rel="stylesheet" href="<?= kneipe_asset('assets/css/' . $style . '.css') ?>">
  <?php endforeach ?>
  <link rel="stylesheet" href="<?= kneipe_asset('assets/css/print.css') ?>" media="print">
  <script src="<?= kneipe_asset('assets/js/site.js') ?>" defer></script>
  <?php snippet('jsonld', ['items' => $jsonld ?? []]) ?>
</head>
