<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\EachCommand;
use Auroro\Workspaces\Composer\LinkCommand;
use Auroro\Workspaces\Composer\UnlinkCommand;
use Auroro\Workspaces\Composer\WorkspacesCommand;
use Auroro\Workspaces\Composer\WorkspacesCommandProvider;
use Composer\Command\BaseCommand;

it('returns four commands', function () {
    $provider = new WorkspacesCommandProvider();
    $commands = $provider->getCommands();

    expect($commands)->toHaveCount(4);
});

it('returns BaseCommand instances', function () {
    $provider = new WorkspacesCommandProvider();
    $commands = $provider->getCommands();

    foreach ($commands as $command) {
        expect($command)->toBeInstanceOf(BaseCommand::class);
    }
});

it('returns commands with correct types', function () {
    $provider = new WorkspacesCommandProvider();
    $commands = $provider->getCommands();

    expect($commands[0])->toBeInstanceOf(LinkCommand::class);
    expect($commands[1])->toBeInstanceOf(UnlinkCommand::class);
    expect($commands[2])->toBeInstanceOf(WorkspacesCommand::class);
    expect($commands[3])->toBeInstanceOf(EachCommand::class);
});

it('returns commands with correct names', function () {
    $provider = new WorkspacesCommandProvider();
    $commands = $provider->getCommands();

    $names = array_map(fn (BaseCommand $c) => $c->getName(), $commands);

    expect($names)->toBe(['link', 'unlink', 'workspaces', 'each']);
});
