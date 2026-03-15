<?php

declare(strict_types=1);

use Auroro\Workspaces\LinkedEntry;

it('serializes to composer repository array', function () {
    $entry = new LinkedEntry(
        url: '/home/dev/auroro/main/packages/*',
        monorepo: 'auroro',
        worktree: 'main',
    );

    expect($entry->toArray())->toBe([
        'type' => 'path',
        'url' => '/home/dev/auroro/main/packages/*',
        'canonical' => false,
        'options' => [
            'symlink' => true,
            'monorepo' => 'auroro',
            'worktree' => 'main',
        ],
    ]);
});

it('deserializes from composer repository array', function () {
    $data = [
        'type' => 'path',
        'url' => '/home/dev/auroro/main/packages/*',
        'canonical' => false,
        'options' => [
            'symlink' => true,
            'monorepo' => 'auroro',
            'worktree' => 'main',
        ],
    ];

    $entry = LinkedEntry::fromArray($data);

    expect($entry->url)->toBe('/home/dev/auroro/main/packages/*');
    expect($entry->monorepo)->toBe('auroro');
    expect($entry->worktree)->toBe('main');
});

it('round-trips through toArray and fromArray', function () {
    $original = new LinkedEntry(
        url: '/path/to/packages/*',
        monorepo: 'test',
        worktree: 'feature',
    );

    $restored = LinkedEntry::fromArray($original->toArray());

    expect($restored->url)->toBe($original->url);
    expect($restored->monorepo)->toBe($original->monorepo);
    expect($restored->worktree)->toBe($original->worktree);
});

it('matches by monorepo tag', function () {
    $entry = new LinkedEntry(url: '/path', monorepo: 'auroro', worktree: 'main');

    expect($entry->belongsTo('auroro'))->toBeTrue();
    expect($entry->belongsTo('other'))->toBeFalse();
});

it('matches by monorepo tag and worktree', function () {
    $entry = new LinkedEntry(url: '/path', monorepo: 'auroro', worktree: 'main');

    expect($entry->belongsToWorktree('auroro', 'main'))->toBeTrue();
    expect($entry->belongsToWorktree('auroro', 'feature'))->toBeFalse();
    expect($entry->belongsToWorktree('other', 'main'))->toBeFalse();
});
