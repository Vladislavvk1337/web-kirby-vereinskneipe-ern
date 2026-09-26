<?php

/**
 * Minimaler SMTP-Empfänger für Tests: nimmt Nachrichten an und legt jede
 * als Datei im Zielordner ab. Kein TLS, keine Anmeldung, nur lokal.
 *
 *   php tests/support/smtp-sink.php <port> <ordner>
 */

[$script, $port, $dir] = $argv + [null, null, null];

if (!$port || !$dir) {
	fwrite(STDERR, "Aufruf: php smtp-sink.php <port> <ordner>\n");
	exit(2);
}

@mkdir($dir, 0777, true);
$server = stream_socket_server('tcp://127.0.0.1:' . (int)$port, $errno, $errstr);

if ($server === false) {
	fwrite(STDERR, "SMTP-Empfänger startet nicht: $errstr\n");
	exit(1);
}

$count = 0;

while ($client = @stream_socket_accept($server, -1)) {
	$send = fn (string $line) => fwrite($client, $line . "\r\n");
	$send('220 localhost Test-SMTP');
	$data   = null;
	$header = '';

	while (($line = fgets($client)) !== false) {
		if ($data !== null) {
			if (rtrim($line, "\r\n") === '.') {
				file_put_contents(sprintf('%s/%03d.eml', $dir, ++$count), $header . $data);
				$data = null;
				$send('250 OK');
			} else {
				$data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
			}
			continue;
		}

		$command = strtoupper(substr(trim($line), 0, 4));

		match ($command) {
			'EHLO', 'HELO' => $send('250 localhost'),
			'MAIL', 'RCPT' => [$header .= 'X-Envelope-' . $command . ': ' . trim(substr($line, 5)) . "\r\n", $send('250 OK')],
			'DATA' => [$data = '', $send('354 Ende mit <CRLF>.<CRLF>')],
			'RSET' => [$header = '', $send('250 OK')],
			'NOOP' => $send('250 OK'),
			'QUIT' => $send('221 Tschüss'),
			default => $send('502 Nicht unterstützt'),
		};

		if ($command === 'QUIT') {
			break;
		}

		if ($command === 'RSET') {
			$header = '';
		}
	}

	fclose($client);
	$header = '';
}
