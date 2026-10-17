<?php
/**
 * Guard test — the Unraid / container template and the `.env` it relies on.
 *
 * Two things here can cost a user their data, so both are locked:
 *   * the template must mount `/var/www/html/data` (the SQLite database) and a
 *     configuration folder — an update with either missing starts from scratch;
 *   * the app must actually honour `EVERSHELF_ENV_FILE`, otherwise the mounted
 *     folder is decoration and Settings writes to a path that disappears.
 *
 * Run: php scripts/test-unraid-template.php
 */

$root = dirname(__DIR__);
$fail = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $fail, $checks;
    $checks++;
    if ($ok) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
}

$xmlPath = $root . '/unraid/evershelf.xml';
check('the template exists', is_file($xmlPath));

$xmlString = is_file($xmlPath) ? (string)file_get_contents($xmlPath) : '';
check('the template is well-formed XML',
    $xmlString !== '' && @simplexml_load_string($xmlString) !== false);

$doc = $xmlString !== '' ? @simplexml_load_string($xmlString) : false;
$configs = [];
if ($doc !== false) {
    foreach ($doc->Config as $config) {
        $configs[] = [
            'name'   => (string)$config['Name'],
            'target' => (string)$config['Target'],
            'type'   => (string)$config['Type'],
            'mode'   => (string)$config['Mode'],
            'value'  => trim((string)$config),
        ];
    }
}

$byTarget = [];
foreach ($configs as $config) {
    $byTarget[$config['target']] = $config;
}

check('the template pulls the published image',
    $doc !== false && strpos((string)$doc->Repository, 'ghcr.io/dadaloop82/evershelf') === 0);
check('it listens on the container port 80',
    isset($byTarget['80']) && $byTarget['80']['type'] === 'Port');
check('the WebUI points at that port',
    $doc !== false && strpos((string)$doc->WebUI, '[PORT:') !== false);
check('Support and Project point at the repository',
    $doc !== false
    && strpos((string)$doc->Support, 'github.com/dadaloop82/EverShelf') !== false
    && strpos((string)$doc->Project, 'github.com/dadaloop82/EverShelf') !== false);
check('an icon is declared', $doc !== false && trim((string)$doc->Icon) !== '');

// The two paths that must never be missing.
check('the DATABASE is on a persistent path (/var/www/html/data)',
    isset($byTarget['/var/www/html/data'])
    && $byTarget['/var/www/html/data']['type'] === 'Path'
    && $byTarget['/var/www/html/data']['mode'] === 'rw'
    && strpos($byTarget['/var/www/html/data']['value'], '/mnt/user/appdata/') === 0);
check('the configuration folder is on a persistent path (/config)',
    isset($byTarget['/config'])
    && $byTarget['/config']['type'] === 'Path'
    && $byTarget['/config']['mode'] === 'rw'
    && strpos($byTarget['/config']['value'], '/mnt/user/appdata/') === 0);
check('the app is told where .env lives',
    isset($byTarget['EVERSHELF_ENV_FILE'])
    && $byTarget['EVERSHELF_ENV_FILE']['type'] === 'Variable'
    && strpos($byTarget['EVERSHELF_ENV_FILE']['value'], '/config/') === 0);
check('the timezone is a normal setting',
    isset($byTarget['TZ']) && $byTarget['TZ']['type'] === 'Variable');

// --------------------------------------------------- the code honours the variable
$custom = sys_get_temp_dir() . '/evershelf-env-path-' . getmypid() . '.env';
$probe = 'require ' . var_export($root . '/api/lib/env.php', true) . '; echo evershelfEnvFile();';
$withVar = trim((string)shell_exec('EVERSHELF_ENV_FILE=' . escapeshellarg($custom)
    . ' php -r ' . escapeshellarg($probe) . ' 2>/dev/null'));
$withoutVar = trim((string)shell_exec('env -u EVERSHELF_ENV_FILE php -r '
    . escapeshellarg($probe) . ' 2>/dev/null'));

check('EVERSHELF_ENV_FILE is honoured', $withVar === $custom);
check('without it the default stays <app>/.env', $withoutVar === $root . '/.env');

// No writer keeps a hardcoded path: they would write a file nobody reads.
$hardcoded = [];
foreach (['api/index.php', 'api/lib/env.php', 'api/lib/calendar_ics.php', 'api/lib/mealie_setup.php'] as $file) {
    $src = (string)file_get_contents($root . '/' . $file);
    if (strpos($src, "__DIR__ . '/../.env'") !== false
        || strpos($src, "dirname(__DIR__, 2) . '/.env'") !== false
        || strpos($src, "EVERSHELF_ROOT . '/.env'") !== false) {
        $hardcoded[] = $file;
    }
}
check('every .env reader/writer goes through evershelfEnvFile()'
    . ($hardcoded ? ': ' . implode(', ', $hardcoded) : ''), $hardcoded === []);

// The docs exist and say the two things that matter.
$wiki = $root . '/docs/wiki/Unraid.md';
check('the Unraid wiki page exists', is_file($wiki));
$wikiText = is_file($wiki) ? (string)file_get_contents($wiki) : '';
check('the wiki explains the data path', strpos($wikiText, '/var/www/html/data') !== false);
check('the wiki explains EVERSHELF_ENV_FILE', strpos($wikiText, 'EVERSHELF_ENV_FILE') !== false);
check('the wiki explains the pairing code', stripos($wikiText, 'pairing code') !== false);
check('the .env.example documents the override',
    strpos((string)file_get_contents($root . '/.env.example'), 'EVERSHELF_ENV_FILE') !== false);
$readme = (string)file_get_contents($root . '/README.md');
check('the README links the Unraid page', strpos($readme, 'wiki/Unraid.md') !== false);

echo "\n{$checks} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
