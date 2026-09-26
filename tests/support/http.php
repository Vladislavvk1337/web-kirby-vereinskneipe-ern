<?php

/**
 * Startet eine eigene Grav-Testinstanz (Kern aus dem Grav-Paket, Projekt-
 * dateien per Symlink, Beispielinhalte aus seed/) mit dem eingebauten
 * PHP-Server. Legt die Testkonten „admin“ (Administration) und
 * „moderation“ (Moderation) an. HTTP-Hilfsfunktionen mit Cookie-Speicher
 * und JSON/JWT für die Admin-API.
 */

final class TestServer
{
	public const ADMIN_PASSWORD = 'Test-Admin-Passwort-1';
	public const MOD_PASSWORD   = 'Test-Moderation-Pass-1';

	public static ?string $base = null;
	public static string $dir;
	public static string $mails;
	private static $smtp = null;
	public static string $data;
	private static $process = null;
	private static array $env = [];

	public static function start(): string
	{
		if (self::$base !== null) {
			return self::$base;
		}

		$root       = dirname(__DIR__, 2);
		self::$dir  = dirname(__DIR__) . '/tmp/grav-' . bin2hex(random_bytes(4));
		self::$data = self::$dir . '/data';
		$port       = 18000 + random_int(0, 999);
		$smtpPort   = 19000 + random_int(0, 999);
		self::$mails = self::$dir . '/mails';

		self::$env = [
			'GRAV_DEV_DIR'       => self::$dir,
			'GRAV_DATA_DIR'      => self::$data,
			'GRAV_DIST_CACHE'    => $root . '/.grav/dist',
			'GRAV_ENVIRONMENT'   => 'production',
			'GRAV_CONFIG'        => 'true',
			'GRAV_CONFIG__system__custom_base_url' => 'http://127.0.0.1:' . $port,
			'KNEIPE_MIN_SECONDS' => '0',
			'KNEIPE_RATE_LIMIT'  => '3',
			// E-Mails an den Test-SMTP-Empfänger (tests/support/smtp-sink.php)
			'GRAV_CONFIG__plugins__email__mailer__engine'          => 'smtp',
			'GRAV_CONFIG__plugins__email__mailer__smtp__server'     => '127.0.0.1',
			'GRAV_CONFIG__plugins__email__mailer__smtp__port'       => (string)$smtpPort,
			'GRAV_CONFIG__plugins__email__mailer__smtp__encryption' => 'none',
			'GRAV_CONFIG__plugins__email__from'                     => 'noreply@example.org',
		];

		register_shutdown_function(function () {
			if (self::$process) {
				proc_terminate(self::$process);
			}

			if (self::$smtp) {
				proc_terminate(self::$smtp);
			}

			if (getenv('KEEP_TEST_DATA') !== '1') {
				remove_dir(self::$dir);
			}
		});

		self::run([$root . '/scripts/dev-server.sh', '--prepare-only']);
		$paths = [
			'GRAV_CACHE_PATH'  => self::$dir . '/cache',
			'GRAV_LOG_PATH'    => self::$dir . '/logs',
			'GRAV_TMP_PATH'    => self::$dir . '/tmp',
			'GRAV_BACKUP_PATH' => self::$dir . '/backup',
		];
		self::$env += $paths;

		self::run([self::$dir . '/grav/bin/plugin', 'kneipe', 'user', '--username=admin', '--email=admin@example.org', '--group=administration', '--name=Test Admin', '--password-stdin'], self::ADMIN_PASSWORD);
		self::run([self::$dir . '/grav/bin/plugin', 'kneipe', 'user', '--username=moderation', '--email=moderation@example.org', '--group=moderation', '--name=Test Moderation', '--password-stdin'], self::MOD_PASSWORD);

		self::$smtp = proc_open(
			[PHP_BINARY, __DIR__ . '/smtp-sink.php', (string)$smtpPort, self::$mails],
			[0 => ['pipe', 'r'], 1 => ['file', self::$dir . '/smtp.log', 'a'], 2 => ['file', self::$dir . '/smtp.log', 'a']],
			$pipes
		);

		self::$process = proc_open(
			[PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $port, 'system/router.php'],
			[0 => ['pipe', 'r'], 1 => ['file', self::$dir . '/server.log', 'a'], 2 => ['file', self::$dir . '/server.log', 'a']],
			$pipes,
			self::$dir . '/grav',
			[...getenv(), ...self::$env]
		);

		self::$base = 'http://127.0.0.1:' . $port;

		for ($i = 0; $i < 50; $i++) {
			usleep(100000);

			if (@fsockopen('127.0.0.1', $port)) {
				return self::$base;
			}
		}

		fail('Testserver startet nicht');
	}

	/**
	 * Befehl mit der Testumgebung ausführen (optional mit STDIN). Grav-CLI
	 * (bin/…) läuft im Grav-Verzeichnis – Grav leitet GRAV_ROOT aus dem
	 * Arbeitsverzeichnis ab.
	 */
	public static function run(array $command, ?string $stdin = null): string
	{
		$cwd     = str_starts_with($command[0], self::$dir . '/grav/') ? self::$dir . '/grav' : dirname(__DIR__, 2);
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, [...getenv(), ...self::$env]);
		fwrite($pipes[0], (string)$stdin);
		fclose($pipes[0]);
		$out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		$code = proc_close($process);

		if ($code !== 0) {
			fail('Befehl fehlgeschlagen (' . implode(' ', $command) . "):\n" . $out);
		}

		return $out;
	}

	public static function pages(): string
	{
		self::start();

		return self::$data . '/pages';
	}

	/** Seitendatei zu einer Route (Ordner mit oder ohne Nummer „01.“) */
	public static function pageFile(string $route): ?string
	{
		$dir = self::pages();

		foreach (array_filter(explode('/', $route)) as $slug) {
			$match = array_values(array_filter(
				glob($dir . '/*', GLOB_ONLYDIR) ?: [],
				fn ($path) => preg_replace('/^\d+\./', '', basename($path)) === $slug
			));

			if ($match === []) {
				return null;
			}

			$dir = $match[0];
		}

		return (glob($dir . '/*.md') ?: [null])[0];
	}

	public static function log(): string
	{
		return (string)@file_get_contents(self::$dir . '/logs/grav.log');
	}
}

final class HttpClient
{
	private array $cookies = [];

	/** @return array{status:int, headers:array<string,string[]>, body:string, json:mixed} */
	public function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
	{
		$ch = curl_init(TestServer::start() . $path);
		$responseHeaders = [];

		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
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

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
		}

		$text   = (string)curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		foreach ($responseHeaders['set-cookie'] ?? [] as $cookie) {
			[$pair] = explode(';', $cookie, 2);
			[$name, $value] = explode('=', $pair, 2) + [1 => ''];
			$this->cookies[$name] = $value;
		}

		return ['status' => $status, 'headers' => $responseHeaders, 'body' => $text, 'json' => json_decode($text, true)];
	}

	public function get(string $path): array
	{
		return $this->request('GET', $path);
	}

	public function post(string $path, array $form, array $headers = []): array
	{
		return $this->request('POST', $path, $form, $headers);
	}

	public function cookies(): array
	{
		return $this->cookies;
	}
}

/** Admin-API mit JWT (wie Admin2) */
final class ApiClient
{
	private HttpClient $http;
	private string $token;

	public function __construct(string $username, string $password)
	{
		$this->http = new HttpClient();
		$login      = $this->http->request('POST', '/api/v1/auth/token', json_encode(['username' => $username, 'password' => $password]), ['Content-Type: application/json']);
		$token      = $login['json']['data']['access_token'] ?? $login['json']['access_token'] ?? null;

		if (!is_string($token)) {
			fail("Anmeldung $username fehlgeschlagen: HTTP {$login['status']} {$login['body']}");
		}

		$this->token = $token;
	}

	public static function admin(): self
	{
		static $client = null;
		return $client ??= new self('admin', TestServer::ADMIN_PASSWORD);
	}

	public static function moderation(): self
	{
		static $client = null;
		return $client ??= new self('moderation', TestServer::MOD_PASSWORD);
	}

	public function call(string $method, string $path, ?array $json = null, array $headers = []): array
	{
		return $this->http->request($method, '/api/v1' . $path, $json === null ? null : json_encode($json), [
			'Authorization: Bearer ' . $this->token,
			'Content-Type: application/json',
			'Accept: application/json',
			...$headers,
		]);
	}

	public function page(string $route): array
	{
		$response = $this->call('GET', '/pages' . $route);

		if ($response['status'] !== 200) {
			fail("Seite $route nicht lesbar: HTTP {$response['status']} {$response['body']}");
		}

		return $response;
	}

	/** Frontmatter ändern (wie Admin2: Teiländerung, ETag) */
	public function update(string $route, array $header, array $extra = []): array
	{
		$etag = $this->page($route)['headers']['etag'][0] ?? '';

		return $this->call('PATCH', '/pages' . $route, ['header' => $header, ...$extra], $etag !== '' ? ['If-Match: ' . $etag] : []);
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

/** Vom Test-SMTP-Empfänger angenommene E-Mails (Inhalt je Datei) */
function received_mails(): array
{
	TestServer::start();
	$files = glob(TestServer::$mails . '/*.eml') ?: [];
	sort($files);

	return array_map(fn ($file) => (string)file_get_contents($file), $files);
}

/** Gespeicherte Anfragen (Dateien) */
function stored_requests(): array
{
	return glob(TestServer::pages() . '/anfragen/*/request.md') ?: [];
}

/** Frontmatter einer Seitendatei */
function page_header(string $file): array
{
	load_grav_vendor();

	return Grav\Plugin\Kneipe\Guard::parseFrontmatter((string)file_get_contents($file));
}
