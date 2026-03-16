<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
