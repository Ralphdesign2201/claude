<?php

declare(strict_types=1);

/** Gemeinsame Hilfen für build-product.php und build-release.php. */

const TOOLS_ROOT = __DIR__;

function projectRoot(): string
{
    return dirname(__DIR__);
}

/** Verzeichnisse, die in Updates und in die Auslieferung gehören (relativ zum Projekt). */
const CODE_DIRS = ['src', 'public', 'bin', 'examples', 'database/migrations'];
const CODE_ROOT_FILES = ['VERSION', '.htaccess', 'README.md', 'composer.json', '.env.example'];

/** Nie in ein Update (der Installer ist nur Teil der Erstauslieferung). */
const NEVER_IN_UPDATE = ['public/install.php'];

/**
 * @return list<string> relative Dateipfade, sortiert
 */
function codeFiles(bool $forUpdate): array
{
    $root = projectRoot();
    $out = [];
    foreach (CODE_DIRS as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#(^|/)(\.DS_Store|Thumbs\.db)$#', $rel) || str_ends_with($rel, '.swp')) {
                continue;
            }
            $out[] = str_replace('\\', '/', $rel);
        }
    }
    foreach (CODE_ROOT_FILES as $f) {
        if (is_file($root . '/' . $f)) {
            $out[] = $f;
        }
    }
    if ($forUpdate) {
        $out = array_values(array_diff($out, NEVER_IN_UPDATE));
    }
    $out = array_values(array_unique($out));
    sort($out);

    return $out;
}

/** @param list<string> $files @return array{product:string,version:string,builtAt:string,minPhp:string,files:list<array{path:string,sha256:string}>} */
function buildManifest(string $product, string $version, array $files, ?array $contentOverrides = null): array
{
    $root = projectRoot();
    $list = [];
    foreach ($files as $rel) {
        $content = $contentOverrides[$rel] ?? (string) file_get_contents($root . '/' . $rel);
        $list[] = ['path' => $rel, 'sha256' => hash('sha256', $content)];
    }

    return ['product' => $product, 'version' => $version, 'builtAt' => gmdate('Y-m-d\TH:i:s\Z'), 'minPhp' => '8.1', 'files' => $list];
}

function currentVersion(): string
{
    return trim((string) @file_get_contents(projectRoot() . '/VERSION')) ?: '0.0.0';
}

function validVersion(string $v): bool
{
    return preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}(-[0-9A-Za-z.]{1,20})?$/', $v) === 1;
}

function fail(string $msg): never
{
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
}

/** @return array<string,string|bool> */
function parseArgs(array $argv): array
{
    $out = ['_' => []];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $out[$m[1]] = $m[2] ?? true;
        } else {
            $out['_'][] = $a;
        }
    }

    return $out;
}

function vendorConfig(): array
{
    $f = TOOLS_ROOT . '/vendor.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

    return is_array($d) ? $d + ['server' => '', 'publicKeys' => []] : ['server' => '', 'publicKeys' => []];
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? removeTree($p) : unlink($p);
        }
    }
    rmdir($dir);
}
