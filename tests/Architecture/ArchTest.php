<?php

declare(strict_types=1);

arch('strict types')
    ->expect('Auroro\Workspaces')
    ->toUseStrictTypes();

arch('no traits')
    ->expect('Auroro\Workspaces')
    ->not->toBeTraits();

arch('no debugging')
    ->expect(['dd', 'dump', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('value objects are readonly')
    ->expect('Auroro\Workspaces\WorkspaceConfig')
    ->toBeReadonly();

arch('linked entry is readonly')
    ->expect('Auroro\Workspaces\LinkedEntry')
    ->toBeReadonly();

arch('result objects are readonly')
    ->expect('Auroro\Workspaces\LinkResult')
    ->toBeReadonly();

arch('package is readonly')
    ->expect('Auroro\Workspaces\Package')
    ->toBeReadonly();

arch('package matcher is readonly')
    ->expect('Auroro\Workspaces\PackageMatcher')
    ->toBeReadonly();

arch('dependency graph is readonly')
    ->expect('Auroro\Workspaces\DependencyGraph')
    ->toBeReadonly();

arch('core does not depend on composer')
    ->expect('Auroro\Workspaces')
    ->not->toUse('Composer')
    ->ignoring('Auroro\Workspaces\Composer');
