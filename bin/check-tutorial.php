<?php

/**
 * Prüft, ob jede eigene Projektdatei im Tutorial (.spec/design/tutorial/*.md) vorkommt.
 *
 *   php bin/check-tutorial.php
 *
 * Neue Datei angelegt? Dann im passenden Kapitel und in 22-dateiverzeichnis.md ergänzen.
 */
$root = dirname(__DIR__);
$skipDirs = ['vendor', 'node_modules', 'var', 'public/build', 'public/bundles', 'public/media', '.spec', '.claude', '.phpunit.cache', 'test-results', 'playwright-report', 'migrations/.gitignore', '.git', '.vscode'];
$skipFiles = ['.env.local', 'composer.lock', 'package-lock.json', '.gitignore'];

$tutorial = '';
if (!is_dir($root.'/.spec/design/tutorial')) {
    echo "Kein Tutorial-Ordner vorhanden – Prüfung übersprungen.\n";
    exit(0);
}
foreach (glob($root.'/.spec/design/tutorial/*.md') ?: [] as $chapter) {
    $tutorial .= file_get_contents($chapter);
}

$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $file) use ($root, $skipDirs): bool {
        $relative = substr($file->getPathname(), strlen($root) + 1);

        return !in_array($relative, $skipDirs, true);
    },
));

$missing = [];
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (in_array(basename($relative), $skipFiles, true)) {
        continue;
    }
    if (!str_contains($tutorial, '`'.$relative.'`')) {
        $missing[] = $relative;
    }
}

sort($missing);
if ([] !== $missing) {
    fwrite(STDERR, "Nicht im Tutorial erwähnt:\n  ".implode("\n  ", $missing)."\n");
    exit(1);
}
echo "Alle Projektdateien kommen im Tutorial vor.\n";
