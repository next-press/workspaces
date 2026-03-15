<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LinkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('link');
        $this->setDescription('Link current worktree packages to global Composer config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $linker = WorkspaceFactory::create($this->requireComposer());
        $result = $linker->linker->link($linker->config);

        foreach ($result->urls as $url) {
            $output->writeln("Linked worktree '<info>{$result->worktreeId}</info>' → {$url}");
        }

        $output->writeln("Config: {$result->configPath}");

        return 0;
    }
}
