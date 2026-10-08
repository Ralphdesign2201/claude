<?php
declare(strict_types=1);

/** Gemeinsame Funktionen der Build-Werkzeuge. */
function tl_root(): string { return dirname(__DIR__); }

/** Dateien, die in Updates ausgeliefert werden: app/, public/, index.php, cron.php  (relativ => sha256) */
function tl_manifest(): array {
    $root = tl_root(); $m = [];
    foreach (['app', 'public'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = $dir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen("$root/$dir") + 1));
            if (str_contains($rel, '/tests/') || preg_match('/\.(tmp|bak|upd[0-9a-f]+)$/', $rel)) continue;
            $m[$rel] = hash_file('sha256', $f->getPathname());
        }
    }
    foreach (['index.php', 'cron.php'] as $f) if (is_file("$root/$f")) $m[$f] = hash_file('sha256', "$root/$f");
    ksort($m);
    return $m;
}
