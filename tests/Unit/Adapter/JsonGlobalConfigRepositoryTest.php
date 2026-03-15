<?php

declare(strict_types=1);

use Auroro\Workspaces\Adapter\JsonGlobalConfigRepository;
use Auroro\Workspaces\LinkedEntry;

function createTempConfig(string $content = ''): string
{
    $dir = sys_get_temp_dir() . '/workspaces-test-' . uniqid();
    mkdir($dir, 0755, true);
    $path = $dir . '/config.json';

    if ($content !== '') {
        file_put_contents($path, $content);
    }

    return $path;
}

function removeTempDir(string $dir): void
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        is_dir($path) ? removeTempDir($path) : unlink($path);
    }

    rmdir($dir);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/workspaces-test-*', GLOB_ONLYDIR) as $dir) {
        removeTempDir($dir);
    }
});

it('returns empty entries for missing file', function () {
    $path = sys_get_temp_dir() . '/workspaces-test-' . uniqid() . '/config.json';
    $repo = new JsonGlobalConfigRepository($path);

    expect($repo->entries())->toBe([]);
});

it('returns empty entries for empty config', function () {
    $path = createTempConfig('{}');
    $repo = new JsonGlobalConfigRepository($path);

    expect($repo->entries())->toBe([]);
});

it('reads linked entries from config', function () {
    $path = createTempConfig(json_encode([
        'repositories' => [
            [
                'type' => 'path',
                'url' => '/home/dev/packages/*',
                'canonical' => false,
                'options' => ['symlink' => true, 'monorepo' => 'auroro', 'worktree' => 'main'],
            ],
        ],
    ]));
    $repo = new JsonGlobalConfigRepository($path);

    $entries = $repo->entries();

    expect($entries)->toHaveCount(1);
    expect($entries[0]->url)->toBe('/home/dev/packages/*');
    expect($entries[0]->monorepo)->toBe('auroro');
    expect($entries[0]->worktree)->toBe('main');
});

it('round-trips entries through save and read', function () {
    $path = createTempConfig('{}');
    $repo = new JsonGlobalConfigRepository($path);

    $entries = [
        new LinkedEntry(url: '/path/a/*', monorepo: 'auroro', worktree: 'main'),
        new LinkedEntry(url: '/path/b/*', monorepo: 'auroro', worktree: 'feature'),
    ];

    $repo->save($entries);
    $read = $repo->entries();

    expect($read)->toHaveCount(2);
    expect($read[0]->url)->toBe('/path/a/*');
    expect($read[1]->url)->toBe('/path/b/*');
});

it('preserves non-repository keys in config', function () {
    $path = createTempConfig(json_encode([
        'config' => ['sort-packages' => true],
    ]));
    $repo = new JsonGlobalConfigRepository($path);

    $repo->save([
        new LinkedEntry(url: '/path/*', monorepo: 'auroro', worktree: 'main'),
    ]);

    $raw = json_decode(file_get_contents($path), true);

    expect($raw['config'])->toBe(['sort-packages' => true]);
    expect($raw['repositories'])->toHaveCount(1);
});

it('serializes empty objects as {} not []', function () {
    $path = createTempConfig(json_encode(['config' => new stdClass()]));
    $repo = new JsonGlobalConfigRepository($path);

    // Save empty entries (removes repositories key)
    $repo->save([]);

    $raw = file_get_contents($path);

    expect($raw)->toContain('"config": {}');
    expect($raw)->not->toContain('"config": []');
});

it('removes repositories key when saving empty entries', function () {
    $path = createTempConfig(json_encode([
        'config' => new stdClass(),
        'repositories' => [
            ['type' => 'path', 'url' => '/old/*', 'options' => ['monorepo' => 'x', 'worktree' => 'y']],
        ],
    ]));
    $repo = new JsonGlobalConfigRepository($path);

    $repo->save([]);

    $raw = json_decode(file_get_contents($path), true);

    expect($raw)->not->toHaveKey('repositories');
});

it('creates parent directory if it does not exist', function () {
    $dir = sys_get_temp_dir() . '/workspaces-test-' . uniqid() . '/nested';
    $path = $dir . '/config.json';
    $repo = new JsonGlobalConfigRepository($path);

    $repo->save([
        new LinkedEntry(url: '/path/*', monorepo: 'auroro', worktree: 'main'),
    ]);

    expect(file_exists($path))->toBeTrue();
});

it('returns the config path', function () {
    $repo = new JsonGlobalConfigRepository('/some/path/config.json');

    expect($repo->path())->toBe('/some/path/config.json');
});
