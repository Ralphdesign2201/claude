<?php
declare(strict_types=1);
/**
 * Baut die beiden Verkaufspakete:  php tools/build_release.php
 *   dist/HandwerkRechnung-<version>-Einzelinstallation.zip   (mit install.php)
 *   dist/HandwerkRechnung-<version>-SaaS.zip                 (mit superinstall.php)
 */
$root = dirname(__DIR__);
require $root . '/app/version.php';
if (!class_exists('ZipArchive')) { fwrite(STDERR, "PHP-Erweiterung zip fehlt\n"); exit(1); }
@mkdir($root . '/dist', 0775, true);
foreach (glob($root . '/dist/*.zip') as $old) unlink($old);

function add_tree(ZipArchive $z, string $root, string $dir, string $prefix): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = $dir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen("$root/$dir") + 1));
        if (str_contains($rel, '__pycache__') || preg_match('#/(tests)/#', $rel)) continue;
        $z->addFile($f->getPathname(), "$prefix/$rel");
    }
}
$variants = ['Einzelinstallation' => ['install.php', 'README-Einzel.md'], 'SaaS' => ['superinstall.php', 'README-SaaS.md']];
foreach ($variants as $name => [$installer, $readme]) {
    $zipFile = "$root/dist/HandwerkRechnung-" . APP_VERSION . "-$name.zip"; $prefix = 'HandwerkRechnung';
    $z = new ZipArchive(); $z->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    add_tree($z, $root, 'app', $prefix); add_tree($z, $root, 'public', $prefix);
    foreach (['index.php', 'cron.php', '.htaccess', $installer, 'SECURITY.md'] as $f) $z->addFile("$root/$f", "$prefix/$f");
    $z->addFile("$root/docs/$readme", "$prefix/README.md");
    foreach (['.htaccess', 'index.html', 'web.config'] as $f) $z->addFile("$root/storage/$f", "$prefix/storage/$f");
    $z->close();
    printf("%s: %.1f KB\n", basename($zipFile), filesize($zipFile) / 1024);
}
