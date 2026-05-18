<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\InjectResult;

it('holds require, require-dev, and autoload-dev counts', function () {
    $result = new InjectResult(requireCount: 5, requireDevCount: 2, autoloadDevCount: 3);

    expect($result->requireCount)->toBe(5);
    expect($result->requireDevCount)->toBe(2);
    expect($result->autoloadDevCount)->toBe(3);
});
