<?php
/**
 * Responsives Bild mit festen Abmessungen, srcset/sizes und WebP.
 *
 * @var Kirby\Cms\File|null $image
 * @var string|null $sizes   z. B. "(min-width: 60em) 50vw, 100vw"
 * @var float|null  $ratio   Seitenverhältnis (Breite/Höhe) für Zuschnitt
 * @var bool|null   $lazy    Lazy Loading (Standard: ja)
 * @var string|null $class
 */
if (!$image) {
	return;
}

$lazy  = $lazy ?? true;
$sizes = $sizes ?? '100vw';
$alt   = $image->decorative()->toBool() ? '' : (string)$image->alt()->value();
$attrs = [
	'class'    => $class ?? null,
	'alt'      => $alt,
	'loading'  => $lazy ? 'lazy' : null,
	'decoding' => 'async',
	'fetchpriority' => $lazy ? null : 'high',
];

if ($image->extension() === 'svg') {
	echo Html::img($image->url(), [...$attrs, 'width' => $width ?? 160, 'height' => $height ?? 160]);
	return;
}

if (isset($ratio) === true) {
	$widths = [480, 800, 1200, 1600];
	$set    = [];

	foreach ($widths as $w) {
		$set[$w . 'w'] = ['width' => $w, 'height' => (int)round($w / $ratio), 'crop' => true];
	}

	$main = $image->thumb(['width' => 1200, 'height' => (int)round(1200 / $ratio), 'crop' => true]);
	$srcset = $image->srcset($set);
} else {
	$main   = $image->thumb(['width' => 1200]);
	$srcset = $image->srcset('default');
}

echo Html::img($main->url(), [
	...$attrs,
	'srcset' => $srcset,
	'sizes'  => $sizes,
	'width'  => $main->width(),
	'height' => $main->height(),
]);
