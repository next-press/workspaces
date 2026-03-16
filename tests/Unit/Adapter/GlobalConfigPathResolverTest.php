<?php

declare(strict_types=1);

use Auroro\Workspaces\Adapter\GlobalConfigPathResolver;

it('resolves to a path ending in config.json', function () {
    $path = GlobalConfigPathResolver::resolve();

    expect($path)->toBeString();
    expect($path)->toEndWith('/config.json');
});

it('resolves to an absolute path', function () {
    $path = GlobalConfigPathResolver::resolve();

    expect($path)->toStartWith('/');
});

it('resolves a path within a composer directory', function () {
    $path = GlobalConfigPathResolver::resolve();

    // Path should contain 'composer' somewhere (composer home dir)
    expect(str_contains(strtolower($path), 'composer'))->toBeTrue();
});
