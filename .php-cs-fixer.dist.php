<?php

// Code-Stil: vendor/bin/php-cs-fixer fix (oder composer check zum Prüfen)
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations', __DIR__.'/bin'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => false,
        'yoda_style' => true,
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');
