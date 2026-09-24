<?php

/**
 * Kleine Ausgabehelfer für Templates und Snippets.
 */

use Kirby\Cms\App;

if (function_exists('kneipe_mark') === false) {
	/**
	 * Hebt Platzhalter der Form „[Platzhalter: …]“ in bereits
	 * maskiertem HTML sichtbar hervor.
	 */
	function kneipe_mark(string $html): string
	{
		return preg_replace(
			'/\[(Platzhalter:[^\]<]*)\]/u',
			'<mark class="placeholder">[$1]</mark>',
			$html
		) ?? $html;
	}

	/** Text maskieren, Zeilenumbrüche erhalten, Platzhalter hervorheben */
	function kneipe_text(string|null $text, bool $breaks = true): string
	{
		$html = htmlspecialchars((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return kneipe_mark($breaks ? nl2br($html, false) : $html);
	}

	/** Versionierte Asset-URL für langes Caching */
	function kneipe_asset(string $path): string
	{
		$kirby = App::instance();
		$file  = $kirby->root('index') . '/' . ltrim($path, '/');
		$query = is_file($file) ? '?v=' . substr(md5((string)filemtime($file)), 0, 8) : '';

		return url($path) . $query;
	}
}
