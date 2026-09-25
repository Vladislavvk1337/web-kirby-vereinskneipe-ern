<?php

/**
 * Statische Prüfungen der Sicherheits- und Datenschutzkonfiguration:
 * Webserver, Grav-Konfiguration, Container, Kubernetes, Repository.
 */

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
$yaml = function (string $file) use ($root): array {
	if (!load_grav_vendor()) {
		fail('symfony/yaml fehlt – zuerst scripts/dev-server.sh --prepare-only');
	}

	return Yaml::parseFile($root . '/' . $file) ?? [];
};

test('Webserver sperrt Konfiguration, Konten, Daten, Anfragen und versteckte Dateien', function () use ($root) {
	$apache = file_get_contents($root . '/docker/apache-grav.conf');

	foreach ([
		'AllowOverride None',
		'(^|/)\.(?!well-known/)',
		'^user/(config|env|accounts|data)(/|$)',
		'^user/pages/(anfragen|einstellungen)(/|$)',
		'^(cache|bin|logs|backup|tmp|tests|webserver-configs)(/|$)',
		'^(system|vendor)/',
		'setup\.php',
		'RemoteIPHeader X-Forwarded-For',
		'disable_functions',
		'open_basedir',
		"script-src 'self';",
		'Options -Indexes',
	] as $needle) {
		assert_contains($needle, $apache, 'apache-grav.conf');
	}
});

test('keine Geheimnisse und Laufzeitdaten im Repository', function () use ($root) {
	$ignore = file_get_contents($root . '/.gitignore');
	foreach (['.env', '/user/accounts/', '/user/pages/', '/user/data/', 'security-private.php', 'api-private.php', '/.grav/'] as $entry) {
		assert_contains($entry, $ignore, '.gitignore');
	}

	foreach (['user/accounts', 'user/pages', 'user/data'] as $dir) {
		assert_false(is_dir($root . '/' . $dir) && (glob($root . '/' . $dir . '/*') ?: []) !== [], "$dir enthält Dateien");
	}

	$example = file_get_contents($root . '/.env.example');
	foreach (['KNEIPE_ADMIN_PASSWORD=', 'GRAV_NONCE_KEY=', 'GRAV_API_JWT_SECRET=', 'GRAV_CONFIG__plugins__email__mailer__smtp__password='] as $key) {
		assert_contains($key . "\n", $example, "$key ohne Wert");
	}

	$secret = file_get_contents($root . '/k8s/base/secret.example.yaml');
	preg_match_all('/^\s+([A-Za-z_]+): "([^"]*)"/m', $secret, $m, PREG_SET_ORDER);
	assert_true(count($m) >= 6, 'Secret-Vorlage vollständig');
	foreach ($m as [, $key, $value]) {
		if ($key !== 'KNEIPE_ADMIN_USERNAME') {
			assert_true(str_starts_with($value, '<'), "$key ist ein Platzhalter");
		}
	}

	$kustomization = file_get_contents($root . '/k8s/base/kustomization.yaml');
	assert_not_contains('- secret.example.yaml', $kustomization, 'Vorlage wird nicht angewendet');
});

test('Grav: sichere Voreinstellungen für Produktion', function () use ($yaml) {
	$system = $yaml('user/config/system.yaml');
	assert_same(0, $system['errors']['display']);
	assert_false($system['twig']['debug']);
	assert_false($system['debugger']['enabled']);
	assert_true($system['session']['lazy'], 'Sitzung erst bei Bedarf (kein Cookie für Gäste)');
	assert_true($system['session']['httponly']);
	assert_same('Lax', $system['session']['samesite']);
	assert_same('multiavatar', $system['accounts']['avatar'], 'keine Gravatar-Anfragen');
	assert_true($system['pages']['markdown']['escape_markup'], 'HTML in Inhalten wird maskiert');
	assert_false($system['pages']['frontmatter']['process_twig']);
	assert_false($system['http_x_forwarded']['ip'], 'Client-IP nur über mod_remoteip');

	foreach (['staging', 'production'] as $env) {
		$override = $yaml("user/env/$env/config/system.yaml");
		assert_same(0, $override['errors']['display'], $env);
		assert_true($override['session']['secure'], "$env: Sitzungs-Cookie nur über HTTPS");
	}

	$security = $yaml('user/config/security.yaml');
	assert_false($security['twig_content']['process_enabled'], 'kein Twig in redaktionellen Inhalten');
	assert_false(isset($security['uploads_dangerous_extensions']), 'Upload-Sperrliste nicht überschreiben (Listen ersetzen statt ergänzen)');
	assert_false(isset($security['xss_dangerous_tags']));

	$api = $yaml('user/config/plugins/api.yaml');
	assert_false($api['auth']['api_keys_enabled']);
	assert_false($api['popularity']['enabled'], 'keine Besucherstatistik');
	assert_same([], $api['cors']['origins']);

	$login = $yaml('user/config/plugins/login.yaml');
	assert_false($login['user_registration']['enabled']);
	assert_true($login['require_trusted_host']);
	assert_false($login['magic_link']['enabled']);

	$groups = $yaml('user/config/groups.yaml');
	assert_false(isset($groups['moderation']['access']['api']['users']), 'Moderation verwaltet keine Konten');
	assert_false(isset($groups['moderation']['access']['api']['super']));
	assert_false(isset($groups['administration']['access']['api']['super']), 'Administration ist kein technischer Superuser');
});

test('keine externen Schriften, Skripte oder Tracker in Theme und Plugin', function () use ($root) {
	$files = [
		...glob($root . '/user/themes/kneipe/templates/*.twig'), ...glob($root . '/user/themes/kneipe/templates/partials/*.twig'),
		...glob($root . '/user/themes/kneipe/css/*.css'), ...glob($root . '/user/themes/kneipe/js/*.js'),
		...glob($root . '/user/plugins/kneipe/admin-next/pages/*.js'),
	];

	assert_true(count($files) > 20, 'Dateien gefunden');

	foreach ($files as $file) {
		$code = file_get_contents($file);
		foreach (['fonts.googleapis', 'fonts.gstatic', 'googletagmanager', 'google-analytics', 'cdn.', 'unpkg', 'maps.google', '<iframe', 'gravatar'] as $bad) {
			assert_not_contains($bad, $code, basename($file));
		}
		assert_not_contains('|raw', str_replace(['kneipe.legalHtml(page.content)|raw'], '', $code), basename($file) . ': |raw nur für bereinigte Rechtstexte');
	}
});

test('Container: nicht als root, Daten im Volume, keine Geheimnisse im Image', function () use ($root) {
	$dockerfile = file_get_contents($root . '/Dockerfile');
	assert_contains('USER 33:33', $dockerfile);
	assert_contains('VOLUME ["/data"]', $dockerfile);
	assert_contains('GRAV_ENVIRONMENT=production', $dockerfile);
	assert_contains('HEALTHCHECK', $dockerfile);
	assert_contains('org.opencontainers.image.source', $dockerfile);

	$code = implode("\n", array_filter(explode("\n", $dockerfile), fn ($line) => !str_starts_with(ltrim($line), '#')));
	foreach (['GRAV_NONCE_KEY', 'GRAV_API_JWT_SECRET', 'smtp__password', 'KNEIPE_ADMIN_PASSWORD'] as $secret) {
		assert_not_contains($secret, $code, 'keine Geheimnisse im Image');
	}

	$grav = file_get_contents($root . '/scripts/grav-dist.sh');
	assert_true(preg_match('/GRAV_SHA256="\$\{GRAV_SHA256:-[0-9a-f]{64}\}"/', $grav) === 1, 'Grav-Paket mit fester Prüfsumme');
	assert_contains('sha256sum -c', $grav);

	$entrypoint = file_get_contents($root . '/scripts/entrypoint.sh');
	assert_contains('set -Eeuo pipefail', $entrypoint);
	assert_contains('mindestens 32 Zeichen', $entrypoint, 'zu kurze Schlüssel verhindern den Start');
	assert_contains('KNEIPE_ALLOW_WEB_SETUP', $entrypoint, 'ohne Konto kein offener Einrichtungsassistent');
	assert_contains('ls -A "$GRAV_DATA_DIR/pages"', $entrypoint, 'Beispielinhalte nur in ein leeres Volume');
	assert_contains('unset KNEIPE_ADMIN_PASSWORD', $entrypoint);
	assert_not_contains('--password=', $entrypoint, 'Passwort nie als Argument');
});

test('Kubernetes: Pod Security „restricted“, Probes, Volume, NetworkPolicy', function () use ($yaml) {
	$deployment = $yaml('k8s/base/deployment.yaml');
	$pod        = $deployment['spec']['template']['spec'];
	$container  = $pod['containers'][0];

	assert_same(1, $deployment['spec']['replicas'], 'ReadWriteOnce: genau ein Pod');
	assert_same('RollingUpdate', $deployment['spec']['strategy']['type']);
	assert_same(0, $deployment['spec']['strategy']['rollingUpdate']['maxSurge'], 'kein zweiter Pod am selben Volume');
	assert_true($pod['securityContext']['runAsNonRoot']);
	assert_same(33, $pod['securityContext']['runAsUser']);
	assert_same('RuntimeDefault', $pod['securityContext']['seccompProfile']['type']);
	assert_false($pod['automountServiceAccountToken']);
	assert_true($container['securityContext']['readOnlyRootFilesystem']);
	assert_false($container['securityContext']['allowPrivilegeEscalation']);
	assert_same(['ALL'], $container['securityContext']['capabilities']['drop']);
	assert_same('/healthz', $container['livenessProbe']['httpGet']['path']);
	assert_same('/readyz', $container['readinessProbe']['httpGet']['path']);
	assert_same('/healthz', $container['startupProbe']['httpGet']['path']);
	assert_true(isset($container['resources']['limits']['memory']));
	assert_same(['/data', '/tmp'], array_column($container['volumeMounts'], 'mountPath'));

	$policy = $yaml('k8s/base/network-policy.yaml');
	assert_same(['Ingress', 'Egress'], $policy['spec']['policyTypes']);

	$namespace = $yaml('k8s/base/namespace.yaml');
	assert_same('restricted', $namespace['metadata']['labels']['pod-security.kubernetes.io/enforce']);

	$production = file_get_contents(dirname(__DIR__, 2) . '/k8s/overlays/production/kustomization.yaml');
	assert_not_contains('newTag: latest', $production, 'Produktion mit fester Version');
});
