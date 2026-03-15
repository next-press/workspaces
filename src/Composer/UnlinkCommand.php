<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class UnlinkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('unlink');
        $this->setDescription('Unlink current worktree from global Composer config');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Remove all worktrees for this monorepo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = (bool) $input->getOption('all');
        $linker = WorkspaceFactory::create($this->requireComposer());
        $result = $linker->linker->unlink($linker->config, $all);

        if ($result->removedCount === 0) {
            $output->writeln($result->all
                ? 'No entries found'
                : "Worktree '<info>{$result->worktreeId}</info>' was not linked");
        } else {
            $output->writeln($result->all
                ? "Removed {$result->removedCount} " . ($result->removedCount === 1 ? 'entry' : 'entries')
                : "Unlinked worktree '<info>{$result->worktreeId}</info>'");
        }

        $output->writeln("Config: {$result->configPath}");

        return 0;
    }
}
