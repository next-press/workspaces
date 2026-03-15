<?php

declare(strict_types=1);

use Auroro\Workspaces\LinkedEntry;
use Auroro\Workspaces\LinkResult;
use Auroro\Workspaces\StatusResult;
use Auroro\Workspaces\UnlinkResult;
use Auroro\Workspaces\WorkspaceConfig;
use Auroro\Workspaces\WorkspaceLinker;
use Auroro\Workspaces\Tests\Fixtures\InMemoryGlobalConfigRepository;

function workspace(string $worktreeId = 'composable', array $globs = ['packages/*']): WorkspaceConfig
{
    return new WorkspaceConfig(
        globs: $globs,
        monorepo: 'auroro',
        worktreeId: $worktreeId,
        rootDir: '/home/dev/auroro/' . $worktreeId,
    );
}

// --- Link ---

it('links a worktree to empty config', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $result = $linker->link(workspace());

    expect($result)->toBeInstanceOf(LinkResult::class);
    expect($result->worktreeId)->toBe('composable');
    expect($result->urls)->toBe(['/home/dev/auroro/composable/packages/*']);
    expect($repo->entries())->toHaveCount(1);
    expect($repo->entries()[0]->url)->toBe('/home/dev/auroro/composable/packages/*');
    expect($repo->entries()[0]->monorepo)->toBe('auroro');
    expect($repo->entries()[0]->worktree)->toBe('composable');
});

it('is idempotent — linking same worktree replaces not duplicates', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace());
    $linker->link(workspace());

    expect($repo->entries())->toHaveCount(1);
});

it('supports multiple worktrees simultaneously', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));
    $linker->link(workspace('main'));

    expect($repo->entries())->toHaveCount(2);
    expect($repo->entries()[0]->worktree)->toBe('composable');
    expect($repo->entries()[1]->worktree)->toBe('main');
});

it('creates multiple entries for multiple globs', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('main', ['packages/*', 'apps/*']));

    expect($repo->entries())->toHaveCount(2);
    expect($repo->entries()[0]->url)->toBe('/home/dev/auroro/main/packages/*');
    expect($repo->entries()[1]->url)->toBe('/home/dev/auroro/main/apps/*');
});

// --- Unlink ---

it('unlinks current worktree only', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));
    $linker->link(workspace('main'));

    $result = $linker->unlink(workspace('composable'));

    expect($result)->toBeInstanceOf(UnlinkResult::class);
    expect($result->removedCount)->toBe(1);
    expect($result->all)->toBeFalse();
    expect($repo->entries())->toHaveCount(1);
    expect($repo->entries()[0]->worktree)->toBe('main');
});

it('unlinks all monorepo entries with all flag', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));
    $linker->link(workspace('main'));

    $result = $linker->unlink(workspace('composable'), all: true);

    expect($result->removedCount)->toBe(2);
    expect($result->all)->toBeTrue();
    expect($repo->entries())->toHaveCount(0);
});

it('returns zero when unlinking non-linked worktree', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $result = $linker->unlink(workspace('nonexistent'));

    expect($result->removedCount)->toBe(0);
});

it('preserves entries from other monorepos when unlinking all', function () {
    $repo = new InMemoryGlobalConfigRepository([
        new LinkedEntry(url: '/other/packages/*', monorepo: 'other-project', worktree: 'main'),
    ]);
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));
    $linker->unlink(workspace('composable'), all: true);

    expect($repo->entries())->toHaveCount(1);
    expect($repo->entries()[0]->monorepo)->toBe('other-project');
});

// --- Status ---

it('returns monorepo entries with current worktree marker', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));
    $linker->link(workspace('main'));

    $result = $linker->status(workspace('composable'));

    expect($result)->toBeInstanceOf(StatusResult::class);
    expect($result->entries)->toHaveCount(2);
    expect($result->currentWorktreeId)->toBe('composable');
});

it('returns empty status when nothing is linked', function () {
    $repo = new InMemoryGlobalConfigRepository();
    $linker = new WorkspaceLinker($repo);

    $result = $linker->status(workspace());

    expect($result->entries)->toHaveCount(0);
});

it('excludes entries from other monorepos in status', function () {
    $repo = new InMemoryGlobalConfigRepository([
        new LinkedEntry(url: '/other/packages/*', monorepo: 'other-project', worktree: 'main'),
    ]);
    $linker = new WorkspaceLinker($repo);

    $linker->link(workspace('composable'));

    $result = $linker->status(workspace('composable'));

    expect($result->entries)->toHaveCount(1);
    expect($result->entries[0]->monorepo)->toBe('auroro');
});
