<?php

/**
 * Migration Kirby → Grav
 *
 * Liest Inhalte (content/), Konten (site/accounts/) und Anfragen einer
 * Kirby-Installation dieses Projekts und schreibt sie im Aufbau des
 * Grav-Themes „kneipe“ in ein ZIELVERZEICHNIS. Die Quelle wird nur gelesen,
 * nie verändert. Das Ziel muss leer sein oder mit --force überschrieben
 * werden dürfen.
 *
 *   php tools/migrate-kirby-to-grav.php --source=/pfad/zu/kirby --target=/pfad/zu/data \
 *       [--grav=/var/www/grav] [--without-requests] [--without-accounts] [--force] [--dry-run]
 *
 * Ergebnis im Ziel:
 *   pages/      Seiten mit Bildern (Grav-Frontmatter, Markdown)
 *   accounts/   Konten (bcrypt-Hashes werden übernommen, Gruppen gesetzt)
 *   MIGRATION.txt  Protokoll: was übernommen wurde, was zu prüfen ist
 *
 * Benötigt symfony/yaml – aus dem Grav-Kern (--grav oder GRAV_ROOT) oder
 * aus vendor/ neben diesem Skript.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

final class KirbyToGrav
{
    /** Kirby-Template => Grav-Template (gleich, außer wo angegeben) */
    private const TEMPLATES = [
        'site' => 'settings',
    ];

    /**
     * Seiten der Administration: Moderation darf sie nicht ändern (Seitenrechte).
     * Nicht für Übersichtsseiten (events, teams, news): Grav vererbt Seitenrechte
     * an Unterseiten ohne eigene Regel – das würde die Termine mitsperren. Die
     * Übersichten schützt Guard.php.
     */
    private const ADMIN_ONLY = ['home', 'about', 'join', 'contact', 'requestform', 'confirmation', 'legal', 'error', 'styleguide'];

    /** Felder mit Writer-HTML, die als Markdown in den Seiteninhalt wandern */
    private const BODY_FIELDS = ['text', 'directions'];

    /** Felder mit Writer-HTML, die als Markdown im Frontmatter bleiben */
    private const MARKDOWN_FIELDS = ['helpers'];

    /** Strukturfelder (YAML in Kirby) */
    private const STRUCTURE_FIELDS = ['openinghours', 'social', 'modelsteps', 'steps', 'faq', 'facts', 'changelog'];

    /** Felder, die nur Wahrheitswerte enthalten */
    private const BOOL_FIELDS = ['allday', 'conflictaccepted', 'consent', 'approvalmode', 'requestreceipt', 'draftnote', 'noindex'];

    /** Datei-Felder: ein Bild bzw. mehrere */
    private const FILE_FIELDS = ['cover', 'logo', 'photo', 'ogimage'];
    private const FILES_FIELDS = ['gallery'];

    /** Seitenverweise (page://uuid oder Pfad) */
    private const PAGE_FIELDS = ['team', 'event', 'slotpage'];

    private array $uuidToRoute = [];
    private array $userIds = [];
    private array $log = [];
    private int $pages = 0;
    private int $files = 0;

    public function __construct(
        private string $source,
        private string $target,
        private bool $withRequests,
        private bool $withAccounts,
        private bool $dryRun,
    ) {
    }

    public function run(): int
    {
        $content = $this->source . '/content';

        if (!is_file($content . '/site.txt')) {
            throw new RuntimeException("Keine Kirby-Inhalte gefunden: $content/site.txt fehlt.");
        }

        // 1. Durchgang: UUIDs den künftigen Grav-Routen zuordnen
        $this->collectUuids($content, '');

        if ($this->withAccounts) {
            $this->collectUsers();
        }

        // 2. Durchgang: Seiten schreiben
        $this->migrateSite($content);
        $this->migrateChildren($content, $this->target . '/pages', '', 0);

        if ($this->withAccounts) {
            $this->migrateAccounts();
        }

        $summary = sprintf('%d Seiten, %d Dateien%s übernommen.', $this->pages, $this->files, $this->withAccounts ? ', ' . count($this->userIds) . ' Konten' : '');
        array_unshift($this->log, $summary, '');

        $report = "Migration Kirby → Grav vom " . date('d.m.Y H:i') . "\nQuelle: {$this->source}\n\n" . implode("\n", $this->log) . "\n";

        if (!$this->dryRun) {
            file_put_contents($this->target . '/MIGRATION.txt', $report);
        }

        fwrite(STDOUT, $report);

        return 0;
    }

    // ------------------------------------------------------------------
    // Kirby lesen
    // ------------------------------------------------------------------

    /** Kirby-Inhaltsdatei in Felder zerlegen (Schlüssel klein) */
    public static function parseKirbyFile(string $file): array
    {
        $raw    = str_replace(["\r\n", "\r"], "\n", (string)file_get_contents($file));
        $raw    = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $fields = [];

        foreach (preg_split('/\n----\s*\n/', "\n" . $raw . "\n") as $chunk) {
            $chunk = trim($chunk, "\n");

            if ($chunk === '' || preg_match('/^([A-Za-z0-9_-]+)\s*:(.*)$/s', $chunk, $m) !== 1) {
                continue;
            }

            $fields[strtolower($m[1])] = trim($m[2]);
        }

        return $fields;
    }

    /** Ordnername → [Sortierzahl|null, Slug] */
    private static function splitFolder(string $folder): array
    {
        if (preg_match('/^(\d+)_(.+)$/', $folder, $m) === 1) {
            return [(int)$m[1], $m[2]];
        }

        return [null, $folder];
    }

    /** Inhaltsdatei einer Kirby-Seite (Template = Dateiname) */
    private static function contentFile(string $dir): ?string
    {
        foreach (glob($dir . '/*.txt') ?: [] as $file) {
            $name = basename($file, '.txt');

            // Metadateien von Bildern (bild.jpg.txt) überspringen
            if (!str_contains($name, '.')) {
                return $file;
            }
        }

        return null;
    }

    private function collectUuids(string $dir, string $route): void
    {
        foreach ($this->childDirs($dir) as [$path, $folder, $draft]) {
            [, $slug] = self::splitFolder($folder);
            $childRoute = $route . '/' . $slug;
            $file       = self::contentFile($path);

            if ($file !== null) {
                $uuid = self::parseKirbyFile($file)['uuid'] ?? '';

                if ($uuid !== '') {
                    $this->uuidToRoute[$uuid] = $childRoute;
                }
            }

            $this->collectUuids($path, $childRoute);
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: bool}> [Pfad, Ordner, Entwurf] */
    private function childDirs(string $dir): array
    {
        $dirs = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '_changes' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            if ($entry === '_drafts') {
                foreach (scandir($dir . '/_drafts') ?: [] as $draft) {
                    if ($draft !== '.' && $draft !== '..' && $draft !== '_changes' && is_dir($dir . '/_drafts/' . $draft)) {
                        $dirs[] = [$dir . '/_drafts/' . $draft, $draft, true];
                    }
                }

                continue;
            }

            $dirs[] = [$dir . '/' . $entry, $entry, false];
        }

        usort($dirs, fn ($a, $b) => strcmp($a[1], $b[1]));

        return $dirs;
    }

    // ------------------------------------------------------------------
    // Seiten schreiben
    // ------------------------------------------------------------------

    private function migrateSite(string $content): void
    {
        $fields = self::parseKirbyFile($content . '/site.txt');
        $header = ['title' => 'Einstellungen', 'name' => $fields['title'] ?? ''];
        unset($fields['title']);

        $header += $this->convertFields($fields, 'settings', $content);
        $header['routable'] = false;
        $header['visible']  = false;
        $header['permissions'] = ['inherit' => false, 'groups' => ['moderation' => '-crud']];

        $this->writePage($this->target . '/pages/einstellungen', 'settings', $header, '');
        $this->log[] = 'Stammdaten und Einstellungen (site.txt) → /einstellungen (nur Administration).';
    }

    private function migrateChildren(string $dir, string $targetDir, string $route, int $depth): void
    {
        foreach ($this->childDirs($dir) as [$path, $folder, $draft]) {
            [$num, $slug] = self::splitFolder($folder);
            $file = self::contentFile($path);

            if ($file === null) {
                $this->log[] = "Übersprungen (keine Inhaltsdatei): $route/$slug";
                continue;
            }

            $template = basename($file, '.txt');
            $template = self::TEMPLATES[$template] ?? $template;
            $isRequest = $template === 'request';

            if ($isRequest && !$this->withRequests) {
                continue;
            }

            $fields = self::parseKirbyFile($file);
            $body   = '';

            foreach (self::BODY_FIELDS as $bodyField) {
                if (isset($fields[$bodyField]) && $body === '') {
                    $body = $template === 'legal' ? $fields[$bodyField] : self::htmlToMarkdown($fields[$bodyField]);
                    unset($fields[$bodyField]);
                }
            }

            $header = ['title' => $fields['title'] ?? $slug];
            unset($fields['title']);
            $header += $this->convertFields($fields, $template, $path);

            // Veröffentlichung: Kirby-Entwürfe bleiben unveröffentlicht
            $header['published'] = !$draft;

            if ($depth === 0 && $num !== null) {
                $header['visible'] = true;
            }

            if ($template === 'legal') {
                // KirbyText kennt einfache Zeilenumbrüche
                $header['markdown'] = ['auto_line_breaks' => true];
            }

            if (in_array($template, ['requests', 'request'], true)) {
                $header['published'] = false;
                $header['routable']  = false;
                $header['visible']   = false;
            }

            if ($template === 'requests') {
                $header['permissions'] = ['inherit' => false, 'groups' => ['moderation' => ['create' => false, 'delete' => false, 'read' => true, 'update' => true]]];
            } elseif (in_array($template, self::ADMIN_ONLY, true)) {
                $header['permissions'] = ['inherit' => false, 'groups' => ['moderation' => '-ud']];
            }

            if ($template === 'error') {
                $header['routable'] = false;
                $header['http_response_code'] = 404;
            }

            if ($template === 'confirmation' || $template === 'styleguide') {
                $header['noindex'] = true;
            }

            // Grav: sichtbare Seiten der obersten Ebene tragen eine Nummer
            $gravFolder = ($depth === 0 && $num !== null) ? sprintf('%02d.%s', $num, $slug) : $slug;
            $gravDir    = $targetDir . '/' . $gravFolder;

            $this->writePage($gravDir, $template, $header, $body);
            $this->copyFiles($path, $gravDir);
            $this->migrateChildren($path, $gravDir, $route . '/' . $slug, $depth + 1);
        }
    }

    /** Felder umwandeln: Wahrheitswerte, Strukturen, Dateien, Verweise, Writer-HTML */
    private function convertFields(array $fields, string $template, string $dir): array
    {
        $header = [];

        foreach ($fields as $key => $value) {
            if ($value === '' && !in_array($key, ['orgstatus', 'category'], true)) {
                continue;
            }

            if (in_array($key, self::BOOL_FIELDS, true)) {
                $header[$key] = in_array(strtolower($value), ['true', '1', 'yes'], true);
            } elseif (in_array($key, self::STRUCTURE_FIELDS, true)) {
                $parsed = Yaml::parse($value);
                $header[$key] = is_array($parsed) ? array_values($parsed) : [];
            } elseif (in_array($key, self::FILE_FIELDS, true)) {
                $files = self::fileList($value);
                if ($files !== []) {
                    $header[$key] = $files[0];
                }
            } elseif (in_array($key, self::FILES_FIELDS, true)) {
                $files = self::fileList($value);
                if ($files !== []) {
                    $header[$key] = array_map(fn ($f) => ['image' => $f], $files);
                }
            } elseif (in_array($key, self::PAGE_FIELDS, true)) {
                $route = $this->resolvePage($value);
                if ($route !== null) {
                    $header[$key] = $route;
                } else {
                    $this->log[] = "Verweis nicht auflösbar ($template.$key): $value";
                }
            } elseif (in_array($key, self::MARKDOWN_FIELDS, true)) {
                $header[$key] = self::htmlToMarkdown($value);
            } elseif ($key === 'handledby') {
                $header[$key] = $this->resolveUser($value);
            } elseif ($key === 'retentiondays') {
                $header[$key] = (int)$value;
            } elseif ($key === 'doors') {
                $header[$key] = substr($value, 0, 5);
            } elseif (in_array($key, ['start', 'end'], true)) {
                $header[$key] = substr($value, 0, 16);
            } else {
                $header[$key] = $value;
            }
        }

        return $header;
    }

    private static function fileList(string $value): array
    {
        $parsed = Yaml::parse($value);
        $list   = is_array($parsed) ? $parsed : [$value];

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? basename(preg_replace('~^file://.*$~', '', $item) ?: $item) : null,
            $list
        )));
    }

    private function resolvePage(string $value): ?string
    {
        $parsed = Yaml::parse($value);
        $first  = is_array($parsed) ? ($parsed[0] ?? null) : $value;

        if (!is_string($first) || $first === '') {
            return null;
        }

        if (str_starts_with($first, 'page://')) {
            return $this->uuidToRoute[substr($first, 7)] ?? null;
        }

        // Kirby-ID (z. B. termine/…): Nummern entfernen
        return '/' . implode('/', array_map(fn ($part) => self::splitFolder($part)[1], explode('/', trim($first, '/'))));
    }

    private function resolveUser(string $value): string
    {
        $parsed = Yaml::parse($value);
        $first  = is_array($parsed) ? ($parsed[0] ?? '') : $value;
        $id     = is_string($first) ? preg_replace('~^user://~', '', $first) : '';

        return $this->userIds[$id]['username'] ?? (string)$id;
    }

    private function writePage(string $dir, string $template, array $header, string $body): void
    {
        $this->pages++;

        if ($this->dryRun) {
            return;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Ordner nicht anlegbar: $dir");
        }

        $yaml = Yaml::dump($header, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($dir . '/' . $template . '.md', "---\n" . $yaml . "---\n" . ($body !== '' ? "\n" . trim($body) . "\n" : ''));
    }

    /** Bilder und ihre Metadaten (bild.jpg.txt → bild.jpg.meta.yaml) */
    private function copyFiles(string $from, string $to): void
    {
        foreach (glob($from . '/*') ?: [] as $file) {
            if (!is_file($file) || str_ends_with($file, '.txt')) {
                continue;
            }

            $name = basename($file);
            $this->files++;

            if ($this->dryRun) {
                continue;
            }

            copy($file, $to . '/' . $name);

            if (is_file($file . '.txt')) {
                $meta = self::parseKirbyFile($file . '.txt');
                $yaml = array_filter([
                    'alt'     => $meta['alt'] ?? null,
                    'caption' => $meta['caption'] ?? null,
                    'credit'  => $meta['credit'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                if ($yaml !== []) {
                    file_put_contents($to . '/' . $name . '.meta.yaml', Yaml::dump($yaml));
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Konten
    // ------------------------------------------------------------------

    private function collectUsers(): void
    {
        foreach (glob($this->source . '/site/accounts/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $index = $dir . '/index.php';

            if (!is_file($index)) {
                continue;
            }

            // Kirby speichert Konten als PHP-Array (Datei aus der eigenen Quelle)
            $data = include $index;

            if (!is_array($data) || !isset($data['email'])) {
                continue;
            }

            $email    = strtolower((string)$data['email']);
            $username = preg_replace('/[^a-z0-9._-]/', '-', explode('@', $email)[0]) ?: 'konto';

            foreach ($this->userIds as $existing) {
                if ($existing['username'] === $username) {
                    $username .= '-' . substr(basename($dir), 0, 4);
                }
            }

            $this->userIds[basename($dir)] = [
                'username' => $username,
                'email'    => $email,
                'name'     => (string)($data['name'] ?? ''),
                'role'     => (string)($data['role'] ?? 'moderator'),
                'hash'     => is_file($dir . '/.htpasswd') ? trim((string)file_get_contents($dir . '/.htpasswd')) : '',
            ];
        }
    }

    private function migrateAccounts(): void
    {
        foreach ($this->userIds as $user) {
            $account = [
                'state'    => 'enabled',
                'email'    => $user['email'],
                'fullname' => $user['name'] !== '' ? $user['name'] : $user['username'],
                'title'    => $user['role'] === 'admin' ? 'Administration' : 'Moderation',
                'language' => 'de',
                'groups'   => [$user['role'] === 'admin' ? 'administration' : 'moderation'],
            ];

            if (str_starts_with($user['hash'], '$2y$') || str_starts_with($user['hash'], '$argon2')) {
                // Grav prüft Passwörter mit password_verify() – Kirby-Hashes bleiben gültig
                $account['hashed_password'] = $user['hash'];
            } else {
                $this->log[] = "Konto {$user['username']}: kein übernehmbares Passwort – bitte neu setzen (Passwort vergessen oder CLI).";
            }

            $this->log[] = "Konto {$user['email']} → {$user['username']} (Gruppe {$account['groups'][0]}).";

            if (!$this->dryRun) {
                @mkdir($this->target . '/accounts', 0775, true);
                file_put_contents($this->target . '/accounts/' . $user['username'] . '.yaml', Yaml::dump($account, 4, 2));
                chmod($this->target . '/accounts/' . $user['username'] . '.yaml', 0660);
            }
        }
    }

    // ------------------------------------------------------------------
    // Writer-HTML → Markdown
    // ------------------------------------------------------------------

    public static function htmlToMarkdown(string $html): string
    {
        if (trim($html) === '' || !str_contains($html, '<')) {
            return trim($html);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('root');
        $md   = $root ? self::blocks($root) : '';

        return trim(preg_replace("/\n{3,}/", "\n\n", $md) ?? $md);
    }

    private static function blocks(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = trim($child->textContent);
                $out .= $text !== '' ? self::escape($text) . "\n\n" : '';
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            $out .= match ($tag) {
                'p'          => self::inline($child) . "\n\n",
                'h1', 'h2'   => '## ' . self::inline($child) . "\n\n",
                'h3'         => '### ' . self::inline($child) . "\n\n",
                'h4', 'h5', 'h6' => '#### ' . self::inline($child) . "\n\n",
                'ul', 'ol'   => self::listItems($child, $tag === 'ol') . "\n",
                'blockquote' => implode("\n", array_map(fn ($l) => '> ' . $l, explode("\n", trim(self::blocks($child))))) . "\n\n",
                'hr'         => "---\n\n",
                default      => self::inline($child) . "\n\n",
            };
        }

        return $out;
    }

    private static function listItems(DOMElement $list, bool $ordered): string
    {
        $out = '';
        $i   = 1;

        foreach ($list->childNodes as $li) {
            if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                $out .= ($ordered ? $i++ . '. ' : '- ') . trim(self::inline($li)) . "\n";
            }
        }

        return $out;
    }

    private static function inline(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= self::escape(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $inner = self::inline($child);

            $out .= match (strtolower($child->tagName)) {
                'strong', 'b' => '**' . trim($inner) . '**',
                'em', 'i'     => '*' . trim($inner) . '*',
                'code'        => '`' . $child->textContent . '`',
                'br'          => "  \n",
                'a'           => '[' . $inner . '](' . str_replace([' ', ')'], ['%20', '%29'], $child->getAttribute('href')) . ')',
                's', 'del'    => '~~' . $inner . '~~',
                default       => $inner,
            };
        }

        return trim($out);
    }

    private static function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text) ?? $text;
    }
}

// ----------------------------------------------------------------------

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
}

$options = getopt('', ['source:', 'target:', 'grav:', 'without-requests', 'without-accounts', 'force', 'dry-run', 'help']);

if (isset($options['help']) || !isset($options['source'], $options['target'])) {
    fwrite(STDERR, "Aufruf: php tools/migrate-kirby-to-grav.php --source=<kirby> --target=<ziel> [--grav=<grav>] [--without-requests] [--without-accounts] [--force] [--dry-run]\n");
    exit(isset($options['help']) ? 0 : 2);
}

$grav = $options['grav'] ?? getenv('GRAV_ROOT') ?: null;

foreach (array_filter([$grav ? $grav . '/vendor/autoload.php' : null, __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../.grav/grav/vendor/autoload.php', '/var/www/grav/vendor/autoload.php']) as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (!class_exists(Yaml::class)) {
    fwrite(STDERR, "symfony/yaml nicht gefunden – bitte --grav=<Grav-Verzeichnis> angeben.\n");
    exit(2);
}

$source = rtrim((string)$options['source'], '/');
$target = rtrim((string)$options['target'], '/');

@mkdir($target, 0775, true);

foreach (['content', 'site', 'media'] as $protected) {
    $dir = realpath($source . '/' . $protected);

    if ($dir !== false && str_starts_with((string)realpath($target) . '/', $dir . '/')) {
        fwrite(STDERR, "Das Ziel darf nicht in den Kirby-Daten ($protected/) liegen.\n");
        exit(2);
    }
}

if (is_dir($target . '/pages') && (glob($target . '/pages/*') ?: []) !== [] && !isset($options['force']) && !isset($options['dry-run'])) {
    fwrite(STDERR, "Ziel $target/pages ist nicht leer. Abbruch (mit --force überschreiben).\n");
    exit(1);
}

try {
    exit((new KirbyToGrav($source, $target, !isset($options['without-requests']), !isset($options['without-accounts']), isset($options['dry-run'])))->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
