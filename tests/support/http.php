<?php

/**
 * Startet den eingebauten PHP-Server mit einer Kopie von content/ und
 * eigenen Laufzeitverzeichnissen. HTTP-Hilfsfunktionen mit Cookie-Speicher.
 */

final class TestServer
{
	public static string|null $base = null;
	public static string $tmp;
	private static $process = null;

	public static function start(array $env = []): string
	{
		if (self::$base !== null) {
			return self::$base;
		}

		$root      = dirname(__DIR__, 2);
		self::$tmp = dirname(__DIR__) . '/tmp/http-' . bin2hex(random_bytes(4));
		copy_dir($root . '/content', self::$tmp . '/content');
		@mkdir(self::$tmp . '/storage', 0777, true);
		@mkdir(self::$tmp . '/media', 0777, true);

		$port = 18000 + random_int(0, 999);
		$vars = [
			'KIRBY_CONTENT_ROOT'   => self::$tmp . '/content',
			'KIRBY_STORAGE_ROOT'   => self::$tmp . '/storage',
			'KIRBY_MEDIA_ROOT'     => self::$tmp . '/media',
			'KIRBY_MAIL_TRANSPORT' => 'none',
			'KIRBY_CONTENT_SALT'   => 'http-test-salt',
			'KIRBY_COOKIE_KEY'     => 'http-test-cookie',
			'KIRBY_DEBUG'          => 'false',
			'KIRBY_ENV_FILE'       => '/dev/null',
			'KNEIPE_MIN_SECONDS'   => '0',
			'KNEIPE_RATE_LIMIT'    => '3',
			...$env,
		];

		self::$process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, 'kirby/router.php'],
			[0 => ['pipe', 'r'], 1 => ['file', self::$tmp . '/server.log', 'a'], 2 => ['file', self::$tmp . '/server.log', 'a']],
			$pipes,
			$root,
			[...getenv(), ...$vars]
		);

		register_shutdown_function(function () {
			if (self::$process) {
				proc_terminate(self::$process);
			}
			remove_dir(self::$tmp);
		});

		self::$base = 'http://127.0.0.1:' . $port;

		for ($i = 0; $i < 50; $i++) {
			usleep(100000);
			if (@fsockopen('127.0.0.1', $port)) {
				return self::$base;
			}
		}

		fail('Testserver startet nicht');
	}
}

final class HttpClient
{
	private array $cookies = [];

	/** @return array{status:int, headers:array<string,string[]>, body:string} */
	public function request(string $method, string $path, array $form = [], array $headers = []): array
	{
		$ch = curl_init(TestServer::start() . $path);
		$responseHeaders = [];

		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => [
				...$headers,
				...($this->cookies ? ['Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies))] : []),
			],
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
				if (str_contains($line, ':')) {
					[$name, $value] = explode(':', $line, 2);
					$responseHeaders[strtolower(trim($name))][] = trim($value);
				}
				return strlen($line);
			},
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
		}

		$body   = (string)curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		foreach ($responseHeaders['set-cookie'] ?? [] as $cookie) {
			[$pair] = explode(';', $cookie, 2);
			[$name, $value] = explode('=', $pair, 2);
			$this->cookies[$name] = $value;
		}

		return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
	}

	public function get(string $path): array
	{
		return $this->request('GET', $path);
	}

	public function post(string $path, array $form, array $headers = []): array
	{
		return $this->request('POST', $path, $form, $headers);
	}
}

/** Formularfelder (hidden) aus HTML lesen */
function hidden_fields(string $html): array
{
	preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $html, $m, PREG_SET_ORDER);
	$fields = [];

	foreach ($m as [, $name, $value]) {
		$fields[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
	}

	return $fields;
}

function request_drafts(): array
{
	return glob(TestServer::$tmp . '/content/anfragen/_drafts/*/request.txt') ?: [];
}
