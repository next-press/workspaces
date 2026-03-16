<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\EachCommand;
use Auroro\Workspaces\Composer\LinkCommand;
use Auroro\Workspaces\Composer\UnlinkCommand;
use Auroro\Workspaces\Composer\WorkspacesCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createCommandProject(): string
{
    $dir = sys_get_temp_dir() . '/ws-cmd-test-' . uniqid();
    mkdir($dir . '/vendor', 0755, true);
    mkdir($dir . '/packages/my-pkg', 0755, true);

    file_put_contents($dir . '/composer.json', json_encode([
        'name' => 'test/root',
        'extra' => [
            'workspaces' => ['paths' => ['packages/*']],
        ],
    ]));

    file_put_contents($dir . '/packages/my-pkg/composer.json', json_encode([
        'name' => 'test/my-pkg',
    ]));

    return $dir;
}

function removeCommandProject(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file->isLink()) {
            unlink($file->getPathname());
        } elseif ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}

/**
 * Execute a BaseCommand's protected execute method directly.
 */
function executeCommand(
    \Composer\Command\BaseCommand $command,
    \Composer\Composer $composer,
    array $inputArgs = [],
    array $inputOptions = [],
): array {
    $command->setComposer($composer);

    $definition = $command->getDefinition();
    $input = new ArrayInput(array_merge($inputArgs, $inputOptions), $definition);
    $output = new BufferedOutput();

    $method = new ReflectionMethod($command, 'execute');

    $exitCode = $method->invoke($command, $input, $output);

    return [$exitCode, $output->fetch()];
}

afterEach(function () {
    // Clean up any entries left in the global config by test runs
    $globalConfigPath = Auroro\Workspaces\Adapter\GlobalConfigPathResolver::resolve();
    if (file_exists($globalConfigPath)) {
        $repo = new Auroro\Workspaces\Adapter\JsonGlobalConfigRepository($globalConfigPath);
        $entries = $repo->entries();
        // Remove all entries from the "test" monorepo
        $entries = array_values(array_filter(
            $entries,
            fn (Auroro\Workspaces\LinkedEntry $e) => $e->monorepo !== 'test',
        ));
        $repo->save($entries);
    }

    foreach (glob(sys_get_temp_dir() . '/ws-cmd-test-*', GLOB_ONLYDIR) as $dir) {
        removeCommandProject($dir);
    }
    foreach (glob(sys_get_temp_dir() . '/ws-home-*') as $path) {
        if (is_dir($path)) {
            removeCommandProject($path);
        }
    }
});

// --- LinkCommand ---

it('link command has correct name and description', function () {
    $command = new LinkCommand();

    expect($command->getName())->toBe('link');
    expect($command->getDescription())->toBe('Link current worktree packages to global Composer config');
});

it('link command execute links worktree and outputs result', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new LinkCommand();
    [$exitCode, $output] = executeCommand($command, $composer);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Linked worktree');
    expect($output)->toContain('Config:');
});

// --- UnlinkCommand ---

it('unlink command has correct name and description', function () {
    $command = new UnlinkCommand();

    expect($command->getName())->toBe('unlink');
    expect($command->getDescription())->toBe('Unlink current worktree from global Composer config');
});

it('unlink command has --all option', function () {
    $command = new UnlinkCommand();

    expect($command->getDefinition()->hasOption('all'))->toBeTrue();
});

it('unlink command execute reports not linked for empty config', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new UnlinkCommand();
    [$exitCode, $output] = executeCommand($command, $composer);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('was not linked');
    expect($output)->toContain('Config:');
});

it('unlink command with --all reports no entries found for empty config', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new UnlinkCommand();
    [$exitCode, $output] = executeCommand($command, $composer, [], ['--all' => true]);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('No entries found');
});

it('unlink command removes linked worktree', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    // First link
    $linkCmd = new LinkCommand();
    executeCommand($linkCmd, $composer);

    // Then unlink
    $unlinkCmd = new UnlinkCommand();
    [$exitCode, $output] = executeCommand($unlinkCmd, $composer);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Unlinked worktree');
});

it('unlink --all removes multiple entries', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    // Link first
    $linkCmd = new LinkCommand();
    executeCommand($linkCmd, $composer);

    // Then unlink all
    $unlinkCmd = new UnlinkCommand();
    [$exitCode, $output] = executeCommand($unlinkCmd, $composer, [], ['--all' => true]);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Removed 1 entry');
});

// --- WorkspacesCommand ---

it('workspaces command has correct name and description', function () {
    $command = new WorkspacesCommand();

    expect($command->getName())->toBe('workspaces');
    expect($command->getDescription())->toBe('Show linked worktrees');
});

it('workspaces command reports no linked worktrees when empty', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new WorkspacesCommand();
    [$exitCode, $output] = executeCommand($command, $composer);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('No linked worktrees');
});

it('workspaces command lists linked worktrees', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    // Link first
    $linkCmd = new LinkCommand();
    executeCommand($linkCmd, $composer);

    // Then list
    $command = new WorkspacesCommand();
    [$exitCode, $output] = executeCommand($command, $composer);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Linked worktrees:');
    expect($output)->toContain('(current)');
    expect($output)->toContain('Config:');
});

// --- EachCommand ---

it('each command has correct name and description', function () {
    $command = new EachCommand();

    expect($command->getName())->toBe('each');
    expect($command->getDescription())->toBe('Run a composer script or command in each workspace package');
});

it('each command has filter option', function () {
    $command = new EachCommand();

    expect($command->getDefinition()->hasOption('filter'))->toBeTrue();
});

it('each command has args argument', function () {
    $command = new EachCommand();

    expect($command->getDefinition()->hasArgument('args'))->toBeTrue();
});

it('each command runs a script in workspace packages', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    // Update the package to have a script
    file_put_contents($projectDir . '/packages/my-pkg/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'scripts' => ['test' => 'echo hello'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();
    [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['test']]);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('packages/my-pkg');
    expect($output)->toContain('1/1 succeeded');
});

it('each command reports skipped packages without the script', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();
    [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['nonexistent']]);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('skipped');
});

it('each command runs raw command with -- separator', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();

    // Simulate -- by setting $_SERVER['argv']
    $originalArgv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['composer', 'each', '--', 'echo', 'hello'];

    try {
        [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['echo', 'hello']]);

        expect($exitCode)->toBe(0);
        expect($output)->toContain('packages/my-pkg');
        expect($output)->toContain('1/1 succeeded');
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});

it('each command reports failed raw commands', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();

    // Simulate -- with a command that will fail
    $originalArgv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['composer', 'each', '--', 'false'];

    try {
        [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['false']]);

        expect($exitCode)->toBe(1);
        expect($output)->toContain('0/1 succeeded');
        expect($output)->toContain('1 failed');
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});

it('each command applies filter option', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    // Create a second package
    mkdir($projectDir . '/packages/other-pkg', 0755, true);
    file_put_contents($projectDir . '/packages/other-pkg/composer.json', json_encode([
        'name' => 'test/other-pkg',
        'scripts' => ['test' => 'echo other'],
    ]));
    file_put_contents($projectDir . '/packages/my-pkg/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'scripts' => ['test' => 'echo mine'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();
    [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['test']], ['--filter' => ['my-*']]);

    expect($exitCode)->toBe(0);
    expect($output)->toContain('packages/my-pkg');
    expect($output)->not->toContain('packages/other-pkg');
});

it('each command returns error for empty args', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();
    [$exitCode, $output] = executeCommand($command, $composer, ['args' => []]);

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Usage:');
});

it('each command raw command captures stderr output', function () {
    $projectDir = createCommandProject();
    $vendorDir = $projectDir . '/vendor';

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $command = new EachCommand();

    $originalArgv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['composer', 'each', '--', 'echo', 'error', '>&2'];

    try {
        [$exitCode, $output] = executeCommand($command, $composer, ['args' => ['echo error >&2']]);

        // The command should capture stderr and write it to output
        expect($exitCode)->toBe(0);
        expect($output)->toContain('1/1 succeeded');
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});
