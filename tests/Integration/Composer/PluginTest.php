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

/**
 * Write a valid installed.json to the vendor/composer directory.
 *
 * @param list<array{name: string, require?: array<string, string>}> $packages
 * @param list<string> $devPackageNames
 */
function writeInstalledJson(string $vendorDir, array $packages, array $devPackageNames = []): void
{
    file_put_contents($vendorDir . '/composer/installed.json', json_encode([
        'packages' => $packages,
        'dev' => true,
        'dev-package-names' => $devPackageNames,
    ]));
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

// --- activate: injection ---

it('activate injects requires when inject is true', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    // Create workspace packages
    $busDir = $projectDir . '/packages/bus';
    mkdir($busDir, 0755, true);
    file_put_contents($busDir . '/composer.json', json_encode([
        'name' => 'test/bus',
        'autoload' => ['psr-4' => ['Test\\Bus\\' => 'src/']],
    ]));

    $clipDir = $projectDir . '/packages/clip';
    mkdir($clipDir, 0755, true);
    file_put_contents($clipDir . '/composer.json', json_encode([
        'name' => 'test/clip',
        'autoload' => ['psr-4' => ['Test\\Clip\\' => 'src/']],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['inject' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    $requires = $composer->getPackage()->getRequires();
    expect($requires)->toHaveKey('test/bus');
    expect($requires)->toHaveKey('test/clip');
});

it('activate injects autoload-dev when inject is true', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    $clipDir = $projectDir . '/packages/clip';
    mkdir($clipDir, 0755, true);
    file_put_contents($clipDir . '/composer.json', json_encode([
        'name' => 'test/clip',
        'autoload' => ['psr-4' => ['Test\\Clip\\' => 'src/']],
        'autoload-dev' => ['psr-4' => ['Test\\Clip\\Tests\\' => 'tests/']],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['inject' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    $devAutoload = $composer->getPackage()->getDevAutoload();
    expect($devAutoload['psr-4'])->toHaveKey('Test\\Clip\\Tests\\');
    expect($devAutoload['psr-4']['Test\\Clip\\Tests\\'])->toBe('packages/clip/tests/');
});

it('activate skips injection when inject is not set', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    $busDir = $projectDir . '/packages/bus';
    mkdir($busDir, 0755, true);
    file_put_contents($busDir . '/composer.json', json_encode([
        'name' => 'test/bus',
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    expect($composer->getPackage()->getRequires())->not->toHaveKey('test/bus');
});

it('activate injects require-dev when inject is true', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    $clipDir = $projectDir . '/packages/clip';
    mkdir($clipDir, 0755, true);
    file_put_contents($clipDir . '/composer.json', json_encode([
        'name' => 'test/clip',
        'require-dev' => ['pestphp/pest' => '^4'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['inject' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    $devRequires = $composer->getPackage()->getDevRequires();
    expect($devRequires)->toHaveKey('pestphp/pest');
    expect($devRequires['pestphp/pest']->getPrettyConstraint())->toBe('^4');
});

it('activate outputs injection summary', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir, 0755, true);

    $busDir = $projectDir . '/packages/bus';
    mkdir($busDir, 0755, true);
    file_put_contents($busDir . '/composer.json', json_encode([
        'name' => 'test/bus',
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['inject' => true, 'paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);

    $output = $io->getOutput();
    expect($output)->toContain('Workspaces:');
    expect($output)->toContain('injected');
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

    // Create installed.json with the vendor package
    writeInstalledJson($vendorDir, [
        ['name' => 'some-vendor/some-package'],
    ]);
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Create a workspace package with bin entry that requires the vendor package
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['some-vendor/some-package' => '^1.0'],
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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, [
        ['name' => 'some-vendor/some-package'],
    ]);

    // Create a workspace package with bin that requires the vendor package
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['some-vendor/some-package' => '^1.0'],
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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, []);

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

    writeInstalledJson($vendorDir, []);

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

// --- scoped symlinks ---

it('onPostInstallOrUpdate only symlinks transitive dependencies', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create vendor packages: a/a depends on b/b, c/c is unrelated
    mkdir($vendorDir . '/a/a', 0755, true);
    mkdir($vendorDir . '/b/b', 0755, true);
    mkdir($vendorDir . '/c/c', 0755, true);

    writeInstalledJson($vendorDir, [
        ['name' => 'a/a', 'require' => ['b/b' => '^1.0']],
        ['name' => 'b/b'],
        ['name' => 'c/c'],
    ]);
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Workspace requires only a/a
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['a/a' => '^1.0'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';

    // a/a and b/b should be symlinked (b/b is transitive dep of a/a)
    expect(is_link($workspaceVendor . '/a/a'))->toBeTrue();
    expect(is_link($workspaceVendor . '/b/b'))->toBeTrue();

    // c/c should NOT be symlinked (not a dependency)
    expect(file_exists($workspaceVendor . '/c/c'))->toBeFalse();
});

it('onPostInstallOrUpdate writes scoped installed.json', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    mkdir($vendorDir . '/a/a', 0755, true);
    mkdir($vendorDir . '/b/b', 0755, true);

    writeInstalledJson($vendorDir, [
        ['name' => 'a/a'],
        ['name' => 'b/b'],
    ], ['b/b']);
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Workspace requires only a/a
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['a/a' => '^1.0'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';
    $scopedInstalled = json_decode(file_get_contents($workspaceVendor . '/composer/installed.json'), true);

    // Only a/a should be in the scoped installed.json
    $names = array_column($scopedInstalled['packages'], 'name');
    expect($names)->toBe(['a/a']);

    // b/b should be filtered from dev-package-names too
    expect($scopedInstalled['dev-package-names'])->toBe([]);
});

it('onPostInstallOrUpdate includes require-dev deps in symlinks', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    mkdir($vendorDir . '/a/a', 0755, true);
    mkdir($vendorDir . '/dev/tool', 0755, true);

    writeInstalledJson($vendorDir, [
        ['name' => 'a/a'],
        ['name' => 'dev/tool'],
    ], ['dev/tool']);
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Workspace has a/a in require and dev/tool in require-dev
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['a/a' => '^1.0'],
        'require-dev' => ['dev/tool' => '^1.0'],
    ]));

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';

    // Both should be symlinked
    expect(is_link($workspaceVendor . '/a/a'))->toBeTrue();
    expect(is_link($workspaceVendor . '/dev/tool'))->toBeTrue();
});

it('onPostInstallOrUpdate removes stale symlinks', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // Create vendor packages
    mkdir($vendorDir . '/a/a', 0755, true);
    mkdir($vendorDir . '/stale/pkg', 0755, true);

    writeInstalledJson($vendorDir, [
        ['name' => 'a/a'],
        ['name' => 'stale/pkg'],
    ]);
    file_put_contents($vendorDir . '/composer/installed.php', '<?php return [];');

    // Workspace requires only a/a
    $pkgDir = $projectDir . '/packages/my-pkg';
    mkdir($pkgDir . '/bin', 0755, true);
    file_put_contents($pkgDir . '/bin/tool', '#!/usr/bin/env php');
    file_put_contents($pkgDir . '/composer.json', json_encode([
        'name' => 'test/my-pkg',
        'bin' => ['bin/tool'],
        'require' => ['a/a' => '^1.0'],
    ]));

    // Pre-create a stale symlink (from a previous unscoped install)
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor/stale';
    mkdir($workspaceVendor, 0755, true);
    symlink($vendorDir . '/stale/pkg', $workspaceVendor . '/pkg');

    [$composer, $io] = composerInstance([
        'workspaces' => ['paths' => ['packages/*']],
    ], $vendorDir);

    $plugin = new Plugin();
    $plugin->activate($composer, $io);
    $plugin->onPostInstallOrUpdate();

    $ws = $projectDir . '/packages/my-pkg/vendor';

    // a/a should be symlinked
    expect(is_link($ws . '/a/a'))->toBeTrue();

    // stale/pkg should have been removed
    expect(file_exists($ws . '/stale/pkg'))->toBeFalse();

    // stale/ dir should also be removed (empty)
    expect(is_dir($ws . '/stale'))->toBeFalse();
});

it('onPostInstallOrUpdate returns early when no installed.json', function () {
    $projectDir = createTempProjectDir();
    $vendorDir = $projectDir . '/vendor';
    mkdir($vendorDir . '/composer', 0755, true);

    // No installed.json created

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

    // Workspace should NOT have a vendor dir (no installed.json = no vendor setup)
    $workspaceVendor = $projectDir . '/packages/my-pkg/vendor';
    expect(is_dir($workspaceVendor))->toBeFalse();
});
