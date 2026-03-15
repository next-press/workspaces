<?php

declare(strict_types=1);

use Auroro\Workspaces\Package;

it('constructs with name, path, and dependencies', function () {
    $package = new Package(
        name: 'auroro/clip',
        path: 'packages/clip',
        dependencies: ['auroro/bus', 'auroro/result'],
    );

    expect($package->name)->toBe('auroro/clip');
    expect($package->path)->toBe('packages/clip');
    expect($package->dependencies)->toBe(['auroro/bus', 'auroro/result']);
});

it('defaults dependencies to empty array', function () {
    $package = new Package(name: 'auroro/result', path: 'packages/result');

    expect($package->dependencies)->toBe([]);
});

it('returns short name after slash', function () {
    $package = new Package(name: 'auroro/clip', path: 'packages/clip');

    expect($package->shortName())->toBe('clip');
});

it('returns full name when no slash', function () {
    $package = new Package(name: 'standalone', path: 'packages/standalone');

    expect($package->shortName())->toBe('standalone');
});

it('returns script command when defined', function () {
    $package = new Package(
        name: 'auroro/clip',
        path: 'packages/clip',
        scripts: ['test' => 'vendor/bin/pest', 'build' => ['step1', 'step2']],
    );

    expect($package->hasScript('test'))->toBeTrue();
    expect($package->script('test'))->toBe('vendor/bin/pest');
    expect($package->script('build'))->toBe('step1 && step2');
});

it('returns null for undefined script', function () {
    $package = new Package(name: 'auroro/clip', path: 'packages/clip');

    expect($package->hasScript('test'))->toBeFalse();
    expect($package->script('test'))->toBeNull();
});

it('resolves @binname to bin entry path in scripts', function () {
    $package = new Package(
        name: 'auroro/lens',
        path: 'packages/lens',
        bin: ['bin/lens'],
        scripts: ['audit' => '@lens audit'],
    );

    expect($package->script('audit'))->toBe('bin/lens audit');
});

it('resolves @php prefix in scripts', function () {
    $package = new Package(
        name: 'auroro/lens',
        path: 'packages/lens',
        scripts: ['audit' => '@php bin/lens audit'],
    );

    expect($package->script('audit'))->toBe('php bin/lens audit');
});

it('strips Composer callbacks from array scripts', function () {
    $package = new Package(
        name: 'auroro/clip',
        path: 'packages/clip',
        scripts: ['demo' => ['Composer\\Config::disableProcessTimeout', 'php demo.php']],
    );

    expect($package->script('demo'))->toBe('php demo.php');
});
