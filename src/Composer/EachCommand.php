<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Auroro\Workspaces\DependencyGraph;
use Auroro\Workspaces\Package;
use Auroro\Workspaces\PackageDiscovery;
use Auroro\Workspaces\PackageMatcher;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class EachCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('each');
        $this->setDescription('Run a composer script or command in each workspace package');
        $this->addOption('filter', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter packages by glob pattern');
        $this->addArgument('args', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Composer script name, or command after --');
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = WorkspaceFactory::create($this->requireComposer());

        $discovery = new PackageDiscovery();
        $packages = $discovery->discover($factory->config->rootDir, $factory->config->globs);
        $graph = new DependencyGraph($packages);

        /** @var list<string> $filters */
        $filters = $input->getOption('filter');
        $matcher = new PackageMatcher($filters);
        $matched = $graph->filter($matcher);

        /** @var list<string> $args */
        $args = $input->getArgument('args');

        if ($args === []) {
            $output->writeln('<error>Usage: composer each <script> or composer each -- <command></error>');

            return 1;
        }

        $isRawCommand = $this->isRawCommand();

        if ($isRawCommand) {
            return $this->runRawCommand($args, $matched, $factory->config->rootDir, $output);
        }

        return $this->runScript($args[0], $matched, $factory->config->rootDir, $output);
    }

    private function runScript(string $scriptName, DependencyGraph $matched, string $rootDir, OutputInterface $output): int
    {
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($matched->topologicalLevels() as $level) {
            $tasks = [];

            foreach ($level as $package) {
                if (! $package->hasScript($scriptName)) {
                    $skipped++;

                    continue;
                }

                /** @var string $command */
                $command = $package->script($scriptName);
                $tasks[] = ['package' => $package, 'command' => $command];
            }

            $results = $this->runLevel($tasks, $rootDir, $output);
            $succeeded += $results['succeeded'];
            $failed += $results['failed'];
        }

        return $this->printSummary($output, $succeeded, $failed, $skipped);
    }

    /** @param list<string> $args */
    private function runRawCommand(array $args, DependencyGraph $matched, string $rootDir, OutputInterface $output): int
    {
        $command = implode(' ', $args);
        $succeeded = 0;
        $failed = 0;

        foreach ($matched->topologicalLevels() as $level) {
            $tasks = array_map(
                fn(Package $p) => ['package' => $p, 'command' => $command],
                $level,
            );

            $results = $this->runLevel($tasks, $rootDir, $output);
            $succeeded += $results['succeeded'];
            $failed += $results['failed'];
        }

        return $this->printSummary($output, $succeeded, $failed, 0);
    }

    /**
     * Run all tasks in a level in parallel, wait for all to complete.
     *
     * @param list<array{package: Package, command: string}> $tasks
     * @return array{succeeded: int, failed: int}
     */
    private function runLevel(array $tasks, string $rootDir, OutputInterface $output): array
    {
        if ($tasks === []) {
            return ['succeeded' => 0, 'failed' => 0];
        }

        // Start all processes
        $running = [];

        foreach ($tasks as $task) {
            $package = $task['package'];
            $command = $task['command'];
            $cwd = $rootDir . '/' . $package->path;

            $output->writeln("<info>{$package->path}</info>: {$command}");

            $proc = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $cwd,
            );

            if (is_resource($proc)) {
                $running[] = [
                    'proc' => $proc,
                    'pipes' => $pipes,
                    'package' => $package,
                ];
            }
        }

        // Wait for all to complete
        $succeeded = 0;
        $failed = 0;

        foreach ($running as $entry) {
            $stdout = stream_get_contents($entry['pipes'][1]);
            $stderr = stream_get_contents($entry['pipes'][2]);

            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);

            $exitCode = proc_close($entry['proc']);

            if ($stdout !== '' && $stdout !== false) {
                $output->write($stdout);
            }

            if ($stderr !== '' && $stderr !== false) {
                $output->write($stderr);
            }

            if ($exitCode === 0) {
                $succeeded++;
            } else {
                $failed++;
                $output->writeln("<error>{$entry['package']->path}</error>: exited with code {$exitCode}");
            }

            $output->writeln('');
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    private function isRawCommand(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        return in_array('--', $argv, true);
    }

    private function printSummary(OutputInterface $output, int $succeeded, int $failed, int $skipped): int
    {
        $total = $succeeded + $failed;
        $summary = "<info>{$succeeded}</info>/{$total} succeeded";

        if ($skipped > 0) {
            $summary .= " ({$skipped} skipped)";
        }

        if ($failed > 0) {
            $summary .= ", <error>{$failed} failed</error>";
        }

        $output->writeln($summary);

        return $failed > 0 ? 1 : 0;
    }
}
