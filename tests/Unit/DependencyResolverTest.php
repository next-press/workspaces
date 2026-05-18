<?php

declare(strict_types=1);

use Auroro\Workspaces\DependencyResolver;

it('resolves direct dependency', function () {
    $resolver = new DependencyResolver([
        'a/b' => ['c/d'],
        'c/d' => [],
    ]);

    expect($resolver->resolve(['a/b']))->toBe(['a/b', 'c/d']);
});

it('resolves transitive chain', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['b/b'],
        'b/b' => ['c/c'],
        'c/c' => [],
    ]);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b', 'c/c']);
});

it('resolves diamond dependency', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['b/b', 'c/c'],
        'b/b' => ['d/d'],
        'c/c' => ['d/d'],
        'd/d' => [],
    ]);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b', 'c/c', 'd/d']);
});

it('skips platform packages', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['php', 'ext-mbstring', 'b/b'],
        'b/b' => [],
    ]);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b']);
});

it('skips composer virtual packages', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['composer-plugin-api', 'composer-runtime-api', 'b/b'],
        'b/b' => [],
    ]);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b']);
});

it('handles circular dependencies', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['b/b'],
        'b/b' => ['a/a'],
    ]);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b']);
});

it('includes unknown seed packages without transitive deps', function () {
    $resolver = new DependencyResolver([
        'a/a' => [],
    ]);

    expect($resolver->resolve(['a/a', 'unknown/pkg']))->toBe(['a/a', 'unknown/pkg']);
});

it('returns empty for empty seeds', function () {
    $resolver = new DependencyResolver([
        'a/a' => ['b/b'],
    ]);

    expect($resolver->resolve([]))->toBe([]);
});

it('returns sorted results', function () {
    $resolver = new DependencyResolver([
        'z/z' => ['a/a'],
        'a/a' => ['m/m'],
        'm/m' => [],
    ]);

    expect($resolver->resolve(['z/z']))->toBe(['a/a', 'm/m', 'z/z']);
});

it('builds from installed.json data', function () {
    $installedJson = [
        'packages' => [
            ['name' => 'a/a', 'require' => ['b/b' => '^1.0']],
            ['name' => 'b/b', 'require' => ['c/c' => '^2.0']],
            ['name' => 'c/c'],
            ['name' => 'd/d', 'require' => ['c/c' => '^2.0']],
        ],
    ];

    $resolver = DependencyResolver::fromInstalledJson($installedJson);

    expect($resolver->resolve(['a/a']))->toBe(['a/a', 'b/b', 'c/c']);
    expect($resolver->resolve(['d/d']))->toBe(['c/c', 'd/d']);
});

it('skips platform packages from seeds', function () {
    $resolver = new DependencyResolver([
        'a/a' => [],
    ]);

    expect($resolver->resolve(['php', 'ext-ffi', 'a/a']))->toBe(['a/a']);
});
