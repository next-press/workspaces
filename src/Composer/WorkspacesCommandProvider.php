<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Composer\Plugin\Capability\CommandProvider;

final class WorkspacesCommandProvider implements CommandProvider
{
    /** @return list<\Composer\Command\BaseCommand> */
    public function getCommands(): array
    {
        return [
            new LinkCommand(),
            new UnlinkCommand(),
            new WorkspacesCommand(),
            new EachCommand(),
        ];
    }
}
