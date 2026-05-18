<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\WorkspaceInjector;
use Auroro\Workspaces\Package;
use Auroro\Workspaces\WorkspaceConfig;
use Composer\Package\Link;
use Composer\Package\RootPackage;

function rootPackage(): RootPackage
{
    return new RootPackage('test/root', '1.0.0.0', '1.0.0');
}

function injectorConfig(?string $rootDir = null): WorkspaceConfig
{
    return new WorkspaceConfig(
        globs: ['packages/*'],
        monorepo: 'test',
        worktreeId: 'main',
        rootDir: $rootDir ?? sys_get_temp_dir(),
        inject: true,
    );
}

// --- require injection ---

it('injects require entries for discovered packages', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(name: 'test/bus', path: 'packages/bus'),
        new Package(name: 'test/clip', path: 'packages/clip'),
    ];

    $result = $injector->inject($root, $packages);

    $requires = $root->getRequires();
    expect($requires)->toHaveKey('test/bus');
    expect($requires)->toHaveKey('test/clip');
    expect($requires['test/bus'])->toBeInstanceOf(Link::class);
    expect($result->requireCount)->toBe(2);
});

it('skips packages already in root requires', function () {
    $root = rootPackage();
    $parser = new \Composer\Semver\VersionParser();
    $root->setRequires([
        'test/bus' => new Link('test/root', 'test/bus', $parser->parseConstraints('*@dev'), Link::TYPE_REQUIRE, '@dev'),
    ]);

    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(name: 'test/bus', path: 'packages/bus'),
        new Package(name: 'test/clip', path: 'packages/clip'),
    ];

    $result = $injector->inject($root, $packages);

    expect($result->requireCount)->toBe(1);
    expect($root->getRequires())->toHaveKey('test/bus');
    expect($root->getRequires())->toHaveKey('test/clip');
});

it('creates links with wildcard constraint', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [new Package(name: 'test/bus', path: 'packages/bus')];
    $injector->inject($root, $packages);

    $link = $root->getRequires()['test/bus'];
    expect($link->getPrettyConstraint())->toBe('*');
});

it('returns zero counts for empty package list', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $result = $injector->inject($root, []);

    expect($result->requireCount)->toBe(0);
    expect($result->autoloadDevCount)->toBe(0);
});

// --- autoload-dev injection ---

it('injects autoload-dev psr-4 with rewritten paths', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            autoloadDev: ['psr-4' => ['Test\\Clip\\Tests\\' => 'tests/']],
        ),
    ];

    $injector->inject($root, $packages);

    $devAutoload = $root->getDevAutoload();
    expect($devAutoload['psr-4'])->toHaveKey('Test\\Clip\\Tests\\');
    expect($devAutoload['psr-4']['Test\\Clip\\Tests\\'])->toBe('packages/clip/tests/');
});

it('skips namespaces already in root autoload-dev', function () {
    $root = rootPackage();
    $root->setDevAutoload([
        'psr-4' => ['Test\\Clip\\Tests\\' => 'existing/path/'],
    ]);

    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            autoloadDev: ['psr-4' => ['Test\\Clip\\Tests\\' => 'tests/']],
        ),
    ];

    $result = $injector->inject($root, $packages);

    expect($result->autoloadDevCount)->toBe(0);
    expect($root->getDevAutoload()['psr-4']['Test\\Clip\\Tests\\'])->toBe('existing/path/');
});

it('infers test namespace when package has tests dir but no autoload-dev', function () {
    $tmpDir = sys_get_temp_dir() . '/ws-injector-test-' . uniqid();
    mkdir($tmpDir . '/packages/bus/tests', 0755, true);

    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig($tmpDir));

    $packages = [
        new Package(
            name: 'test/bus',
            path: 'packages/bus',
            autoload: ['psr-4' => ['Test\\Bus\\' => 'src/']],
        ),
    ];

    $result = $injector->inject($root, $packages);

    $devAutoload = $root->getDevAutoload();
    expect($devAutoload['psr-4'])->toHaveKey('Test\\Bus\\Tests\\');
    expect($devAutoload['psr-4']['Test\\Bus\\Tests\\'])->toBe('packages/bus/tests/');
    expect($result->autoloadDevCount)->toBe(1);

    // Cleanup
    rmdir($tmpDir . '/packages/bus/tests');
    rmdir($tmpDir . '/packages/bus');
    rmdir($tmpDir . '/packages');
    rmdir($tmpDir);
});

it('does not infer test namespace when tests dir does not exist', function () {
    $tmpDir = sys_get_temp_dir() . '/ws-injector-test-' . uniqid();
    mkdir($tmpDir . '/packages/bus', 0755, true);

    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig($tmpDir));

    $packages = [
        new Package(
            name: 'test/bus',
            path: 'packages/bus',
            autoload: ['psr-4' => ['Test\\Bus\\' => 'src/']],
        ),
    ];

    $result = $injector->inject($root, $packages);

    expect($root->getDevAutoload()['psr-4'] ?? [])->toBe([]);
    expect($result->autoloadDevCount)->toBe(0);

    // Cleanup
    rmdir($tmpDir . '/packages/bus');
    rmdir($tmpDir . '/packages');
    rmdir($tmpDir);
});

it('merges with existing root autoload-dev entries', function () {
    $root = rootPackage();
    $root->setDevAutoload([
        'psr-4' => ['App\\Tests\\' => 'tests/'],
    ]);

    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            autoloadDev: ['psr-4' => ['Test\\Clip\\Tests\\' => 'tests/']],
        ),
    ];

    $result = $injector->inject($root, $packages);

    $psr4 = $root->getDevAutoload()['psr-4'];
    expect($psr4)->toHaveKey('App\\Tests\\');
    expect($psr4)->toHaveKey('Test\\Clip\\Tests\\');
    expect($result->autoloadDevCount)->toBe(1);
});

// --- require-dev injection ---

it('injects require-dev entries from packages', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            requireDev: ['pestphp/pest' => '^4', 'phpstan/phpstan' => '^2'],
        ),
    ];

    $result = $injector->inject($root, $packages);

    $devRequires = $root->getDevRequires();
    expect($devRequires)->toHaveKey('pestphp/pest');
    expect($devRequires)->toHaveKey('phpstan/phpstan');
    expect($devRequires['pestphp/pest']->getPrettyConstraint())->toBe('^4');
    expect($result->requireDevCount)->toBe(2);
});

it('skips workspace packages in require-dev', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            requireDev: ['test/bus' => '^1', 'pestphp/pest' => '^4'],
        ),
        new Package(name: 'test/bus', path: 'packages/bus'),
    ];

    $result = $injector->inject($root, $packages);

    $devRequires = $root->getDevRequires();
    expect($devRequires)->toHaveKey('pestphp/pest');
    expect($devRequires)->not->toHaveKey('test/bus');
    expect($result->requireDevCount)->toBe(1);
});

it('skips entries already in root dev requires', function () {
    $root = rootPackage();
    $parser = new \Composer\Semver\VersionParser();
    $root->setDevRequires([
        'pestphp/pest' => new Link('test/root', 'pestphp/pest', $parser->parseConstraints('^4'), Link::TYPE_DEV_REQUIRE, '^4'),
    ]);

    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            requireDev: ['pestphp/pest' => '^4', 'phpstan/phpstan' => '^2'],
        ),
    ];

    $result = $injector->inject($root, $packages);

    expect($result->requireDevCount)->toBe(1);
    expect($root->getDevRequires())->toHaveKey('phpstan/phpstan');
});

it('deduplicates require-dev across packages', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            requireDev: ['pestphp/pest' => '^4'],
        ),
        new Package(
            name: 'test/bus',
            path: 'packages/bus',
            requireDev: ['pestphp/pest' => '^3'],
        ),
    ];

    $result = $injector->inject($root, $packages);

    // First-wins: ^4 from clip
    expect($result->requireDevCount)->toBe(1);
    expect($root->getDevRequires()['pestphp/pest']->getPrettyConstraint())->toBe('^4');
});

it('skips php and ext entries in require-dev', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            requireDev: ['php' => '>=8.3', 'ext-ffi' => '*', 'pestphp/pest' => '^4'],
        ),
    ];

    $result = $injector->inject($root, $packages);

    expect($result->requireDevCount)->toBe(1);
    expect($root->getDevRequires())->not->toHaveKey('php');
    expect($root->getDevRequires())->not->toHaveKey('ext-ffi');
    expect($root->getDevRequires())->toHaveKey('pestphp/pest');
});

it('returns combined counts from require and autoload-dev injection', function () {
    $root = rootPackage();
    $injector = new WorkspaceInjector(injectorConfig());

    $packages = [
        new Package(
            name: 'test/clip',
            path: 'packages/clip',
            autoloadDev: ['psr-4' => ['Test\\Clip\\Tests\\' => 'tests/']],
        ),
        new Package(
            name: 'test/bus',
            path: 'packages/bus',
            autoloadDev: ['psr-4' => ['Test\\Bus\\Tests\\' => 'tests/']],
        ),
    ];

    $result = $injector->inject($root, $packages);

    expect($result->requireCount)->toBe(2);
    expect($result->autoloadDevCount)->toBe(2);
});
