<?php

declare(strict_types=1);

use Auroro\Workspaces\DependencyGraph;
use Auroro\Workspaces\Package;
use Auroro\Workspaces\PackageMatcher;

it('builds from a list of packages', function () {
    $graph = new DependencyGraph([
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
    ]);

    expect($graph->packages())->toHaveCount(2);
});

it('returns topological order for linear chain', function () {
    $graph = new DependencyGraph([
        new Package('auroro/clip', 'packages/clip', ['auroro/bus']),
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
        new Package('auroro/result', 'packages/result'),
    ]);

    $order = $graph->topologicalOrder();

    $resultPos = array_search('auroro/result', $order);
    $busPos = array_search('auroro/bus', $order);
    $clipPos = array_search('auroro/clip', $order);

    expect($resultPos)->toBeLessThan($busPos);
    expect($busPos)->toBeLessThan($clipPos);
});

it('returns topological order for diamond dependency', function () {
    $graph = new DependencyGraph([
        new Package('auroro/app', 'packages/app', ['auroro/left', 'auroro/right']),
        new Package('auroro/left', 'packages/left', ['auroro/core']),
        new Package('auroro/right', 'packages/right', ['auroro/core']),
        new Package('auroro/core', 'packages/core'),
    ]);

    $order = $graph->topologicalOrder();

    $corePos = array_search('auroro/core', $order);
    $leftPos = array_search('auroro/left', $order);
    $rightPos = array_search('auroro/right', $order);
    $appPos = array_search('auroro/app', $order);

    expect($corePos)->toBeLessThan($leftPos);
    expect($corePos)->toBeLessThan($rightPos);
    expect($leftPos)->toBeLessThan($appPos);
    expect($rightPos)->toBeLessThan($appPos);
});

it('returns stable order for packages without dependencies', function () {
    $graph = new DependencyGraph([
        new Package('auroro/code', 'packages/code'),
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/epoch', 'packages/epoch'),
    ]);

    $order = $graph->topologicalOrder();

    expect($order)->toBe(['auroro/code', 'auroro/epoch', 'auroro/result']);
});

it('handles disconnected components', function () {
    $graph = new DependencyGraph([
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/epoch', 'packages/epoch'),
    ]);

    $order = $graph->topologicalOrder();

    expect($order)->toHaveCount(3);

    $resultPos = array_search('auroro/result', $order);
    $busPos = array_search('auroro/bus', $order);
    expect($resultPos)->toBeLessThan($busPos);
});

it('serializes to array', function () {
    $graph = new DependencyGraph([
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
    ]);

    $array = $graph->toArray();

    expect($array)->toHaveKey('packages');
    expect($array)->toHaveKey('topological_levels');

    expect($array['packages']['auroro/result'])->toBe([
        'path' => 'packages/result',
        'dependencies' => [],
    ]);
    expect($array['packages']['auroro/bus'])->toBe([
        'path' => 'packages/bus',
        'dependencies' => ['auroro/result'],
    ]);
    expect($array['topological_levels'])->toBe([
        ['auroro/result'],
        ['auroro/bus'],
    ]);
});

it('returns single level for independent packages', function () {
    $graph = new DependencyGraph([
        new Package('auroro/code', 'packages/code'),
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/epoch', 'packages/epoch'),
    ]);

    $levels = $graph->topologicalLevels();

    expect($levels)->toHaveCount(1);

    $names = array_map(fn ($p) => $p->name, $levels[0]);
    expect($names)->toBe(['auroro/code', 'auroro/epoch', 'auroro/result']);
});

it('returns one level per step in linear chain', function () {
    $graph = new DependencyGraph([
        new Package('auroro/clip', 'packages/clip', ['auroro/bus']),
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
        new Package('auroro/result', 'packages/result'),
    ]);

    $levels = $graph->topologicalLevels();

    expect($levels)->toHaveCount(3);

    expect($levels[0][0]->name)->toBe('auroro/result');
    expect($levels[1][0]->name)->toBe('auroro/bus');
    expect($levels[2][0]->name)->toBe('auroro/clip');
});

it('groups diamond siblings in same level', function () {
    $graph = new DependencyGraph([
        new Package('auroro/app', 'packages/app', ['auroro/left', 'auroro/right']),
        new Package('auroro/left', 'packages/left', ['auroro/core']),
        new Package('auroro/right', 'packages/right', ['auroro/core']),
        new Package('auroro/core', 'packages/core'),
    ]);

    $levels = $graph->topologicalLevels();

    expect($levels)->toHaveCount(3);

    expect($levels[0][0]->name)->toBe('auroro/core');

    $midNames = array_map(fn ($p) => $p->name, $levels[1]);
    expect($midNames)->toBe(['auroro/left', 'auroro/right']);

    expect($levels[2][0]->name)->toBe('auroro/app');
});

it('mixes independent and dependent packages across levels', function () {
    $graph = new DependencyGraph([
        new Package('auroro/bus', 'packages/bus', ['auroro/result']),
        new Package('auroro/result', 'packages/result'),
        new Package('auroro/epoch', 'packages/epoch'),
    ]);

    $levels = $graph->topologicalLevels();

    expect($levels)->toHaveCount(2);

    $firstNames = array_map(fn ($p) => $p->name, $levels[0]);
    expect($firstNames)->toBe(['auroro/epoch', 'auroro/result']);

    expect($levels[1][0]->name)->toBe('auroro/bus');
});

it('filters packages by matcher', function () {
    $graph = new DependencyGraph([
        new Package('auroro/phpx', 'packages/phpx'),
        new Package('auroro/phpx-lsp', 'packages/phpx-lsp'),
        new Package('auroro/clip', 'packages/clip'),
    ]);

    $filtered = $graph->filter(new PackageMatcher(['phpx*']));

    expect($filtered->packages())->toHaveCount(2);

    $names = array_map(fn ($p) => $p->name, $filtered->packages());
    expect($names)->toContain('auroro/phpx');
    expect($names)->toContain('auroro/phpx-lsp');
});
