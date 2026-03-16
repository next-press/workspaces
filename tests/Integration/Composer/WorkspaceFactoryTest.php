<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\WorkspaceFactory;
use Auroro\Workspaces\WorkspaceConfig;
use Auroro\Workspaces\WorkspaceLinker;

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/ws-test-*') as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
    foreach (glob(sys_get_temp_dir() . '/ws-home-*') as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
});

it('creates a WorkspaceFactory from Composer instance', function () {
    [$composer, $io] = composerInstance();

    $factory = WorkspaceFactory::create($composer);

    expect($factory)->toBeInstanceOf(WorkspaceFactory::class);
    expect($factory->linker)->toBeInstanceOf(WorkspaceLinker::class);
    expect($factory->config)->toBeInstanceOf(WorkspaceConfig::class);
});

it('creates a WorkspaceFactory with Composer home', function () {
    [$composer, $io] = composerInstance();

    $factory = WorkspaceFactory::createWithComposerHome($composer);

    expect($factory)->toBeInstanceOf(WorkspaceFactory::class);
    expect($factory->linker)->toBeInstanceOf(WorkspaceLinker::class);
    expect($factory->config)->toBeInstanceOf(WorkspaceConfig::class);
});

it('defaults to packages/* when no extra config', function () {
    [$composer, $io] = composerInstance();

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->globs)->toBe(['packages/*']);
});

it('extracts globs from extra.workspaces.paths', function () {
    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*', 'apps/*']],
    ]);

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->globs)->toBe(['packages/*', 'apps/*']);
});

it('extracts graphPath from extra.workspaces.graph', function () {
    [$composer, $io] = composerInstance([
        'workspaces' => [
            'paths' => ['packages/*'],
            'graph' => '.github/workspace.json',
        ],
    ]);

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->graphPath)->toBe('.github/workspace.json');
});

it('sets graphPath to null when not configured', function () {
    [$composer, $io] = composerInstance();

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->graphPath)->toBeNull();
});

it('uses vendor-dir parent as rootDir when directory exists', function () {
    $vendorDir = sys_get_temp_dir() . '/ws-test-' . uniqid();
    mkdir($vendorDir, 0755, true);

    [$composer, $io] = composerInstance([], $vendorDir);

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->rootDir)->toBe(dirname($vendorDir));

    rmdir($vendorDir);
});

it('falls back to cwd when vendor-dir parent does not exist', function () {
    $vendorDir = '/nonexistent/path/vendor';

    [$composer, $io] = composerInstance([], $vendorDir);

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->rootDir)->toBe((string) getcwd());
});

it('extracts monorepo vendor from package name', function () {
    [$composer, $io] = composerInstance();

    $factory = WorkspaceFactory::create($composer);

    expect($factory->config->monorepo)->toBe('test');
});

it('uses directory basename as worktreeId', function () {
    $vendorDir = sys_get_temp_dir() . '/ws-test-' . uniqid();
    mkdir($vendorDir, 0755, true);

    [$composer, $io] = composerInstance([], $vendorDir);

    $factory = WorkspaceFactory::create($composer);

    $expectedWorktreeId = basename(dirname($vendorDir));
    expect($factory->config->worktreeId)->toBe($expectedWorktreeId);

    rmdir($vendorDir);
});
