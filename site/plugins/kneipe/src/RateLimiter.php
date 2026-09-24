<?php

namespace Kneipe;

/**
 * Einfaches Rate-Limit mit Dateispeicher.
 *
 * Gespeichert wird nur ein HMAC der IP-Adresse (mit geheimem Salz) und
 * die Zeitpunkte der Versuche – keine IP-Adresse im Klartext. Einträge
 * verfallen nach Ablauf des Zeitfensters.
 */
final class RateLimiter
{
	public function __construct(
		private string $directory,
		private string $secret,
		private int $limit = 5,
		private int $window = 3600,
	) {
	}

	/**
	 * Zählt einen Versuch und meldet, ob er noch erlaubt ist.
	 */
	public function hit(string $client, int|null $now = null): bool
	{
		$now  = $now ?? time();
		$file = $this->file($client);

		if (is_dir($this->directory) === false) {
			@mkdir($this->directory, 0770, true);
		}

		$handle = @fopen($file, 'c+');

		if ($handle === false) {
			// Speicher nicht verfügbar: nicht blockieren, aber auch nichts zählen
			return true;
		}

		flock($handle, LOCK_EX);
		$data  = json_decode(stream_get_contents($handle) ?: '[]', true);
		$times = array_values(array_filter(
			is_array($data) ? $data : [],
			fn ($time): bool => is_int($time) && $time > $now - $this->window
		));

		$allowed = count($times) < $this->limit;
		$times[] = $now;

		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, json_encode($times));
		flock($handle, LOCK_UN);
		fclose($handle);

		return $allowed;
	}

	/** Entfernt abgelaufene Einträge */
	public function prune(int|null $now = null): int
	{
		$now     = $now ?? time();
		$removed = 0;

		foreach (glob($this->directory . '/*.json') ?: [] as $file) {
			if (filemtime($file) < $now - $this->window) {
				@unlink($file) && $removed++;
			}
		}

		return $removed;
	}

	private function file(string $client): string
	{
		return $this->directory . '/' . hash_hmac('sha256', $client, $this->secret) . '.json';
	}
}
