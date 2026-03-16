<?php

declare(strict_types=1);

use Auroro\Workspaces\Composer\Plugin;
use Auroro\Workspaces\Composer\WorkspacesCommandProvider;
use Composer\IO\BufferIO;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Script\ScriptEvents;

function createTempProjectDir(): string
{
    $dir = sys_get_temp_dir() . '/ws-plugin-test-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeTempProjectDir(string $dir): void
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

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/ws-plugin-test-*', GLOB_ONLYDIR) as $dir) {
        removeTempProjectDir($dir);
    }
    foreach (glob(sys_get_temp_dir() . '/ws-test-*') as $path) {
        if (is_dir($path)) {
            removeTempProjectDir($path);
        }
    }
    foreach (glob(sys_get_temp_dir() . '/ws-home-*') as $path) {
        if (is_dir($path)) {
            removeTempProjectDir($path);
        }
    }
});

// --- activate ---

it('activate skips link when autolink is not set', function () {
    [$composer, $io] = composerInstance();

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    // No output means no link was performed
    expect($io->getOutput())->toBe('');
});

it('activate skips link when autolink is false', function () {
    [$composer, $io] = composerInstance([
        'workspaces' => ['autolink' => false, 'paths' => ['packages/*']],
    ]);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    expect($io->getOutput())->toBe('');
});

it('activate calls link when autolink is true', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    [$composer, $io] = composerInstance([
        'workspaces' => ['autolink' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    // When autolink is true, link is called.
    // The config home directory gets a config.json written to it.
    /** @var string $home */
    $home = $composer->getConfig()->get('home');
    expect(file_exists($home . '/config.json'))->toBeTrue();
});

// --- deactivate ---

it('deactivate does nothing', function () {
    [$composer, $io] = composerInstance();

    $plugin = new Plugin();
    $plugin->deactivate($composer, $io);

    expect($io->getOutput())->toBe('');
});

// --- uninstall ---

it('uninstall skips unlink when autolink is not set', function () {
    [$composer, $io] = composerInstance();

    $plugin = new Plugin();
    $plugin->uninstall($composer, $io);

    expect($io->getOutput())->toBe('');
});

it('uninstall skips unlink when autolink is false', function () {
    [$composer, $io] = composerInstance([
        'workspaces' => ['autolink' => false, 'paths' => ['packages/*']],
    ]);

    $plugin = new Plugin();
    $plugin->uninstall($composer, $io);

    expect($io->getOutput())->toBe('');
});

it('uninstall calls unlink when autolink is true', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    [$composer, $io] = composerInstance([
        'workspaces' => ['autolink' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    // First activate to create config file
    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    // Now uninstall should call unlink
    $plugin->uninstall($composer, $io);

    /** @var string $home */
    $home = $composer->getConfig()->get('home');
    // Config file should still exist but have no repositories
    $config = json_decode(file_get_contents($home . '/config.json'), true);
    expect($config)->not->toHaveKey('repositories');
});

// --- getCapabilities ---

it('returns CommandProvider mapping in capabilities', function () {
    $plugin = new Plugin();
    $capabilities = $plugin->getCapabilities();

    expect($capabilities)->toHaveKey(CommandProvider::class);
    expect($capabilities[CommandProvider::class])->toBe(WorkspacesCommandProvider::class);
});

// --- getSubscribedEvents ---

it('subscribes to post install and post update events', function () {
    $events = Plugin::getSubscribedEvents();

    expect($events)->toHaveKey(ScriptEvents::POST_INSTALL_CMD);
    expect($events)->toHaveKey(ScriptEvents::POST_UPDATE_CMD);
    expect($events[ScriptEvents::POST_INSTALL_CMD])->toBe('onPostInstallOrUpdate');
    expect($events[ScriptEvents::POST_UPDATE_CMD])->toBe('onPostInstallOrUpdate');
});

// --- onPostInstallOrUpdate ---

it('onPostInstallOrUpdate writes workspace.json to vendor dir', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a package
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir, 0755, true);
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    expect(file_exists($vendorDir . '/workspace.json'))->toBeTrue();

    $graph = json_decode(file_get_contents($vendorDir . '/workspace.json'), true);
    expect($graph)->toHaveKey('packages');
    expect($graph['packages'])->toHaveKey('test/my-pkg');
});

it('onPostInstallOrUpdate writes graph to custom path when configured', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a package
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir, 0755, true);
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => [
            'paths' => ['packages/*'],
            'graph' => '.github/workspace.json',
        ],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $customPath = $projectDir . '/.github/workspace.json';
    expect(file_exists($customPath))->toBeTrue();

    $graph = json_decode(file_get_contents($customPath), true);
    expect($graph)->toHaveKey('packages');
});

it('onPostInstallOrUpdate does nothing when composer is null', function () {
    // Create a Plugin without activating it (composer/io remain null)
    $plugin = new Plugin();
    $plugin->onPostInstallOrUpdate();

    // Should not throw — just return early
    expect(true)->toBeTrue();
});

it('onPostInstallOrUpdate symlinks vendor packages for workspace with bin', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a root vendor package to be symlinked
    $vendorPkgDir = $vendorDir . '/some-vendor/some-package';
    mkdir($vendorPkgDir, 0755, true);
    file_put_contents($vendorPkgDir . '/composer.json', '{}');

    // Create installed.json for composer metadata
    file_put_contents($vendorDir . '/composer/installed.json', '{}');
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // The workspace should have a vendor directory with symlinked packages
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';
    expect(is_dir($workspaceVendor))->toBeTrue();
    expect(is_dir($workspaceVendor . '/composer'))->toBeTrue();

    // Composer metadata should be copied
    expect(file_exists($workspaceVendor . '/composer/installed.json'))->toBeTrue();
    expect(file_exists($workspaceVendor . '/composer/installed.php'))->toBeTrue();

    // Vendor package should be symlinked
    $symlinkPath = $workspaceVendor . '/some-vendor/some-package';
    expect(is_link($symlinkPath))->toBeTrue();
    expect(readlink($symlinkPath))->toBe($vendorPkgDir);
});

it('onPostInstallOrUpdate skips packages without bin or lock', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a workspace package with no bin and no composer.lock
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir, 0755, true);
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // The workspace should NOT have a vendor directory since it has no bin or lock
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';
    expect(is_dir($workspaceVendor))->toBeFalse();

    // But workspace.json should still be written
    expect(file_exists($vendorDir . '/workspace.json'))->toBeTrue();
});

it('onPostInstallOrUpdate copies bin proxies from root vendor', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a root bin proxy
    $rootBinDir = $vendorDir . '/bin';
    mkdir($rootBinDir, 0755, true);
    file_put_contents($rootBinDir . '/pest', '#!/usr/bin/env php');
    chmod($rootBinDir . '/pest', 0755);

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // Bin proxy should be copied
    $workspaceBin = $projectDir . '/packages/my-pkg/vendor/bin/pest';
    expect(file_exists($workspaceBin))->toBeTrue();
    expect(decoct(fileperms($workspaceBin) & 0777))->toBe('755');
});

it('onPostInstallOrUpdate does not overwrite existing symlinks', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a root vendor package
    $vendorPkgDir = $vendorDir . '/some-vendor/some-package';
    mkdir($vendorPkgDir, 0755, true);
    file_put_contents($vendorPkgDir . '/marker.txt', 'original');

    // Create a workspace package with bin
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    // Pre-create the symlink
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor/some-vendor';
    mkdir($workspaceVendor, 0755, true);
    symlink($vendorPkgDir, $workspaceVendor . '/some-package');

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // Symlink should still exist and point to the same place
    expect(is_link($workspaceVendor . '/some-package'))->toBeTrue();
    expect(readlink($workspaceVendor . '/some-package'))->toBe($vendorPkgDir);
});

it('onPostInstallOrUpdate installs workspaces with composer.lock', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a workspace package with composer.lock (no bin)
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir, 0755, true);
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
    ]));
    file_put_contents($pkgDir . '/composer.lock', '{}');

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // The workspace should have a vendor directory (because of composer.lock)
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';
    expect(is_dir($workspaceVendor))->toBeTrue();
    expect(is_dir($workspaceVendor . '/composer'))->toBeTrue();
});

it('onPostInstallOrUpdate outputs installing vendors message', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a workspace package with bin
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $output = $io->getOutput();
    expect($output)->toContain('Workspaces:');
    expect($output)->toContain('graph written to');
    expect($output)->toContain('installing vendors');
});

it('onPostInstallOrUpdate skips non-file entries in bin dir', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a root bin directory with a subdirectory (not a file)
    $rootBinDir = $vendorDir . '/bin';
    mkdir($rootBinDir . '/subdir', 0755, true);

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // The subdir should NOT be copied to the workspace bin dir
    $workspaceBinSubdir = $projectDir . '/packages/my-pkg/vendor/bin/subdir';
    expect(file_exists($workspaceBinSubdir))->toBeFalse();
});

it('onPostInstallOrUpdate skips existing bin proxies', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a root bin proxy
    $rootBinDir = $vendorDir . '/bin';
    mkdir($rootBinDir, 0755, true);
    file_put_contents($rootBinDir . '/pest', '#!/usr/bin/env php new');

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    // Pre-create the workspace bin proxy
    $workspaceBinDir = $projectDir . '/packages/my-pkg/vendor/bin';
    mkdir($workspaceBinDir, 0755, true);
    file_put_contents($workspaceBinDir . '/pest', '#!/usr/bin/env php old');

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // The existing file should NOT be overwritten
    expect(file_get_contents($workspaceBinDir . '/pest'))->toBe('#!/usr/bin/env php old');
});

it('onPostInstallOrUpdate handles dump-autoload success for workspace packages', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $output = $io->getOutput();
    // Dump-autoload should succeed and report vendor linked
    expect($output)->toContain('my-pkg');
    expect($output)->toContain('vendor linked');
});

it('onPostInstallOrUpdate skips non-dir entries in vendor', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create a file (not a directory) directly in vendor
    file_put_contents($vendorDir . '/autoload.php', '<?php // autoload');

    // Create a workspace package with bin entry
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    // Should complete without error
    $output = $io->getOutput();
    expect($output)->toContain('installing vendors');
});
