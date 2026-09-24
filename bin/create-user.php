<?php

/**
 * Benutzerkonto anlegen – für jede Person ein eigenes Konto, keine
 * gemeinsam genutzten Redaktionszugänge.
 *
 *   php bin/create-user.php --email=name@example.org --name="Vorname Nachname" --role=admin
 *   php bin/create-user.php --email=name@example.org --name="Vorname Nachname" --role=moderator
 *
 * Das Passwort wird abgefragt (mindestens 12 Zeichen) oder – für
 * Automatisierung – aus der Umgebungsvariable KIRBY_NEW_PASSWORD gelesen.
 * Auf dem Server: sudo kneipe-cli create-user --email=… (siehe docs/deployment.md)
 */

$kirby = require __DIR__ . '/kirby.php';

$options = getopt('', ['email:', 'name:', 'role:', 'language::']);
$email   = trim($options['email'] ?? '');
$name    = trim($options['name'] ?? '');
$role    = $options['role'] ?? 'moderator';

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
	fwrite(STDERR, "Aufruf: php bin/create-user.php --email=… --name=\"…\" --role=admin|moderator\n");
	exit(2);
}

if (in_array($role, ['admin', 'moderator'], true) === false) {
	fwrite(STDERR, "Rolle muss admin oder moderator sein.\n");
	exit(2);
}

if ($kirby->users()->findBy('email', $email)) {
	fwrite(STDERR, "Ein Konto mit $email gibt es bereits.\n");
	exit(1);
}

$password = getenv('KIRBY_NEW_PASSWORD') ?: null;

if ($password === null) {
	fwrite(STDOUT, 'Passwort (mind. 12 Zeichen): ');
	system('stty -echo 2>/dev/null');
	$password = trim((string)fgets(STDIN));
	system('stty echo 2>/dev/null');
	fwrite(STDOUT, "\nPasswort wiederholen: ");
	system('stty -echo 2>/dev/null');
	$repeat = trim((string)fgets(STDIN));
	system('stty echo 2>/dev/null');
	fwrite(STDOUT, "\n");

	if ($password !== $repeat) {
		fwrite(STDERR, "Die Passwörter stimmen nicht überein.\n");
		exit(1);
	}
}

if (mb_strlen($password) < 12) {
	fwrite(STDERR, "Das Passwort muss mindestens 12 Zeichen lang sein.\n");
	exit(1);
}

$user = $kirby->impersonate('kirby', fn () => $kirby->users()->create([
	'email'    => $email,
	'name'     => $name,
	'role'     => $role,
	'language' => $options['language'] ?? 'de',
	'password' => $password,
]));

echo "✔ Konto angelegt: {$user->email()} ({$role})\n";
