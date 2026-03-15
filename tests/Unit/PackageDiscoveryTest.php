<?php

declare(strict_types=1);

use Auroro\Workspaces\PackageDiscovery;

function createTempMonorepo(): string
{
    $dir = sys_get_temp_dir() . '/workspaces-discovery-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function addPackage(string $root, string $path, string $name, array $require = []): void
{
    $dir = $root . '/' . $path;
    mkdir($dir, 0755, true);

    $composer = ['name' => $name];

    if ($require !== []) {
        $composer['require'] = $require;
    }

    file_put_contents($dir . '/composer.json', json_encode($composer));
}

function removeDir(string $dir): void
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        is_dir($path) ? removeDir($path) : unlink($path);
    }

    rmdir($dir);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/workspaces-discovery-*', GLOB_ONLYDIR) as $dir) {
        removeDir($dir);
    }
});

it('discovers packages from glob patterns', function () {
    $root = createTempMonorepo();
    addPackage($root, 'packages/clip', 'auroro/clip');
    addPackage($root, 'packages/bus', 'auroro/bus');

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*']);

    expect($packages)->toHaveCount(2);

    $names = array_map(fn ($p) => $p->name, $packages);
    expect($names)->toContain('auroro/clip');
    expect($names)->toContain('auroro/bus');
});

it('returns relative paths', function () {
    $root = createTempMonorepo();
    addPackage($root, 'packages/clip', 'auroro/clip');

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*']);

    expect($packages[0]->path)->toBe('packages/clip');
});

it('skips directories without composer.json', function () {
    $root = createTempMonorepo();
    addPackage($root, 'packages/clip', 'auroro/clip');
    mkdir($root . '/packages/empty', 0755, true);

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*']);

    expect($packages)->toHaveCount(1);
    expect($packages[0]->name)->toBe('auroro/clip');
});

it('only includes internal dependencies', function () {
    $root = createTempMonorepo();
    addPackage($root, 'packages/result', 'auroro/result');
    addPackage($root, 'packages/bus', 'auroro/bus', [
        'auroro/result' => 'self.version',
        'psr/container' => '^2.0',
    ]);

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*']);

    $bus = array_values(array_filter($packages, fn ($p) => $p->name === 'auroro/bus'))[0];

    expect($bus->dependencies)->toBe(['auroro/result']);
});

it('handles multiple globs', function () {
    $root = createTempMonorepo();
    addPackage($root, 'packages/clip', 'auroro/clip');
    addPackage($root, 'apps/checkmate', 'auroro/checkmate');

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*', 'apps/*']);

    expect($packages)->toHaveCount(2);

    $names = array_map(fn ($p) => $p->name, $packages);
    expect($names)->toContain('auroro/clip');
    expect($names)->toContain('auroro/checkmate');
});

it('returns empty list for non-matching globs', function () {
    $root = createTempMonorepo();

    $discovery = new PackageDiscovery();
    $packages = $discovery->discover($root, ['packages/*']);

    expect($packages)->toBe([]);
});
