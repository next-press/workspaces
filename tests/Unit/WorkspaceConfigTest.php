<?php

declare(strict_types=1);

use Auroro\Workspaces\WorkspaceConfig;

it('holds configuration values', function () {
    $config = new WorkspaceConfig(
        globs: ['packages/*'],
        monorepo: 'auroro',
        worktreeId: 'composable',
        rootDir: '/home/dev/auroro/composable',
    );

    expect($config->globs)->toBe(['packages/*']);
    expect($config->monorepo)->toBe('auroro');
    expect($config->worktreeId)->toBe('composable');
    expect($config->rootDir)->toBe('/home/dev/auroro/composable');
});

it('resolves single glob to absolute URL', function () {
    $config = new WorkspaceConfig(
        globs: ['packages/*'],
        monorepo: 'auroro',
        worktreeId: 'main',
        rootDir: '/home/dev/auroro/main',
    );

    expect($config->resolvedUrls())->toBe([
        '/home/dev/auroro/main/packages/*',
    ]);
});

it('resolves multiple globs to absolute URLs', function () {
    $config = new WorkspaceConfig(
        globs: ['packages/*', 'apps/*'],
        monorepo: 'auroro',
        worktreeId: 'main',
        rootDir: '/home/dev/auroro/main',
    );

    expect($config->resolvedUrls())->toBe([
        '/home/dev/auroro/main/packages/*',
        '/home/dev/auroro/main/apps/*',
    ]);
});

it('defaults graphPath to null', function () {
    $config = new WorkspaceConfig(
        globs: ['packages/*'],
        monorepo: 'auroro',
        worktreeId: 'main',
        rootDir: '/home/dev/auroro/main',
    );

    expect($config->graphPath)->toBeNull();
});

it('holds custom graphPath', function () {
    $config = new WorkspaceConfig(
        globs: ['packages/*'],
        monorepo: 'auroro',
        worktreeId: 'main',
        rootDir: '/home/dev/auroro/main',
        graphPath: '.github/workspace.json',
    );

    expect($config->graphPath)->toBe('.github/workspace.json');
});
