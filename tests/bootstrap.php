<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Testdatenbank (SQLite, siehe .env.test) frisch aufbauen: Schema, Grunddaten, Demo-Objekte
if ('test' === ($_SERVER['APP_ENV'] ?? null) && !getenv('SKIP_DB_RESET')) {
    $console = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach ([
        'doctrine:schema:drop --force --full-database',
        'doctrine:schema:create',
        'app:setup',
        'app:demo --items=14',
        'app:user:create admin@example.com --password=test-passwort-123',
    ] as $command) {
        exec($console.' '.$command.' --env=test --no-interaction 2>&1', $output, $code);
        if (0 !== $code) {
            fwrite(STDERR, "Fehler bei \"$command\":\n".implode("\n", $output)."\n");
            exit(1);
        }
    }
}
