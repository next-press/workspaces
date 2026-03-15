<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WorkspacesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('workspaces');
        $this->setDescription('Show linked worktrees');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $linker = WorkspaceFactory::create($this->requireComposer());
        $result = $linker->linker->status($linker->config);

        if ($result->entries === []) {
            $output->writeln('No linked worktrees');

            return 0;
        }

        $output->writeln('Linked worktrees:');

        foreach ($result->entries as $entry) {
            $marker = $entry->worktree === $result->currentWorktreeId ? ' <comment>(current)</comment>' : '';
            $output->writeln("  <info>{$entry->worktree}</info>{$marker}");
            $output->writeln("    → {$entry->url}");
        }

        $output->writeln('');
        $output->writeln("Config: {$result->configPath}");

        return 0;
    }
}
