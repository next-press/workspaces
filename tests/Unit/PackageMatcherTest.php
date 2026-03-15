<?php

declare(strict_types=1);

use Auroro\Workspaces\Package;
use Auroro\Workspaces\PackageMatcher;

function pkg(string $name): Package
{
    return new Package(name: $name, path: 'packages/' . explode('/', $name)[1] ?? $name);
}

it('matches all packages with wildcard pattern', function () {
    $matcher = new PackageMatcher(['*']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/bus')))->toBeTrue();
});

it('matches exact short name', function () {
    $matcher = new PackageMatcher(['clip']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/bus')))->toBeFalse();
});

it('matches glob prefix on short name', function () {
    $matcher = new PackageMatcher(['cl*']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/bus')))->toBeFalse();
});

it('excludes packages with ! prefix', function () {
    $matcher = new PackageMatcher(['*', '!clip']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeFalse();
    expect($matcher->matches(pkg('auroro/bus')))->toBeTrue();
});

it('matches full name when pattern contains slash', function () {
    $matcher = new PackageMatcher(['auroro/*']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeTrue();
});

it('matches all when patterns are empty', function () {
    $matcher = new PackageMatcher([]);

    expect($matcher->matches(pkg('auroro/clip')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/bus')))->toBeTrue();
});

it('applies multiple exclusions', function () {
    $matcher = new PackageMatcher(['*', '!clip', '!bus']);

    expect($matcher->matches(pkg('auroro/clip')))->toBeFalse();
    expect($matcher->matches(pkg('auroro/bus')))->toBeFalse();
    expect($matcher->matches(pkg('auroro/result')))->toBeTrue();
});

it('matches phpx prefix across multiple packages', function () {
    $matcher = new PackageMatcher(['phpx*']);

    expect($matcher->matches(pkg('auroro/phpx')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/phpx-lsp')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/phpx-native')))->toBeTrue();
    expect($matcher->matches(pkg('auroro/clip')))->toBeFalse();
});
