<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Auroro\Workspaces\DependencyGraph;
use Auroro\Workspaces\DependencyResolver;
use Auroro\Workspaces\Package;
use Auroro\Workspaces\PackageDiscovery;
use Auroro\Workspaces\WorkspaceConfig;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

final class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $factory = WorkspaceFactory::createWithComposerHome($composer);

        if ($factory->config->inject) {
            $this->injectWorkspaces($composer, $io, $factory->config);
        }

        if ($composer->getPackage()->getExtra()['workspaces']['autolink'] ?? false) {
            $factory->linker->link($factory->config);
        }
    }

    private function injectWorkspaces(Composer $composer, IOInterface $io, WorkspaceConfig $config): void
    {
        $discovery = new PackageDiscovery();
        $packages = $discovery->discover($config->rootDir, $config->globs);

        $injector = new WorkspaceInjector($config);
        $result = $injector->inject($composer->getPackage(), $packages);

        $parts = [
            "{$result->requireCount} requires",
            "{$result->requireDevCount} require-dev",
            "{$result->autoloadDevCount} autoload-dev",
        ];

        if ($result->autoloadFilesCount > 0) {
            $parts[] = "{$result->autoloadFilesCount} autoload-files";
        }

        $io->write('<comment>Workspaces:</comment> injected ' . implode(', ', $parts) . ' entries');
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $extra = $composer->getPackage()->getExtra();
        if (!($extra['workspaces']['autolink'] ?? false)) {
            return;
        }

        $factory = WorkspaceFactory::createWithComposerHome($composer);
        $factory->linker->unlink($factory->config, all: true);
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => WorkspacesCommandProvider::class,
        ];
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    public function onPostInstallOrUpdate(): void
    {
        $this->dumpDependencyGraph();
        $this->installWorkspaces();
    }

    private function dumpDependencyGraph(): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }

        $factory = WorkspaceFactory::create($this->composer);

        $discovery = new PackageDiscovery();
        $packages = $discovery->discover($factory->config->rootDir, $factory->config->globs);
        $graph = new DependencyGraph($packages);

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $outputPath = $vendorDir . '/workspace.json';
        $json = json_encode($graph->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($outputPath, $json);

        $this->io->write('');
        $this->io->write("<comment>Workspaces:</comment> graph written to {$outputPath}");

        if ($factory->config->graphPath !== null) {
            $customPath = $factory->config->rootDir . '/' . $factory->config->graphPath;
            $customDir = dirname($customPath);

            if (! is_dir($customDir)) {
                mkdir($customDir, 0755, true);
            }

            file_put_contents($customPath, $json);
            $this->io->write("<comment>Workspaces:</comment> graph written to {$customPath}");
        }
    }

    private function installWorkspaces(): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }

        $factory = WorkspaceFactory::create($this->composer);
        $rootDir = $factory->config->rootDir;
        /** @var string $rootVendor */
        $rootVendor = $this->composer->getConfig()->get('vendor-dir');

        $discovery = new PackageDiscovery();
        $packages = $discovery->discover($rootDir, $factory->config->globs);

        $targets = array_filter(
            $packages,
            fn(Package $p) => $p->bin !== [] || file_exists($rootDir . '/' . $p->path . '/composer.lock'),
        );

        if ($targets === []) {
            return;
        }

        // Parse installed.json for dependency resolution
        $installedJsonPath = $rootVendor . '/composer/installed.json';

        if (! file_exists($installedJsonPath)) {
            return;
        }

        $installedJsonRaw = file_get_contents($installedJsonPath);

        if ($installedJsonRaw === false) {
            return;
        }

        /** @var array{packages: list<array{name: string, require?: array<string, string>}>, dev: bool, dev-package-names: list<string>} $installedJsonData */
        $installedJsonData = json_decode($installedJsonRaw, true);

        $resolver = DependencyResolver::fromInstalledJson($installedJsonData);

        $this->io->write('');
        $this->io->write('<comment>Workspaces:</comment> installing vendors...');

        // Collect all vendor package dirs (skip composer/, bin/, autoload.php)
        $allVendorPackages = $this->collectVendorPackages($rootVendor);

        // Process each workspace in parallel: symlink + dump-autoload
        $processes = [];

        foreach ($targets as $package) {
            $workspaceVendor = $rootDir . '/' . $package->path . '/vendor';

            // Resolve transitive deps for this workspace
            $seeds = array_merge(
                array_keys($package->require),
                array_keys($package->requireDev),
            );

            $resolvedDeps = $resolver->resolve($seeds);
            $resolvedSet = array_flip($resolvedDeps);

            $scopedVendorPackages = array_values(array_filter(
                $allVendorPackages,
                fn (string $pkg) => isset($resolvedSet[$pkg]),
            ));

            $this->symlinkVendor($rootVendor, $workspaceVendor, $scopedVendorPackages);
            $this->writeScopedInstalledJson($workspaceVendor, $installedJsonData, $resolvedSet);

            // Start dump-autoload in background
            $cmd = sprintf('composer dump-autoload -d %s -q', escapeshellarg($rootDir . '/' . $package->path));
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (is_resource($proc)) {
                $processes[] = [
                    'proc' => $proc,
                    'pipes' => $pipes,
                    'package' => $package,
                ];
            }
        }

        // Wait for all dump-autoload processes
        foreach ($processes as $entry) {
            $stdout = stream_get_contents($entry['pipes'][1]);
            $stderr = stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            $exitCode = proc_close($entry['proc']);

            $name = $entry['package']->shortName();

            if ($exitCode === 0) {
                $this->io->write("  <info>{$name}</info>: vendor linked");
            } else {
                $this->io->write("  <error>{$name}</error>: dump-autoload failed (exit {$exitCode})");

                if ($stderr !== '' && $stderr !== false) {
                    $this->io->write("    {$stderr}");
                }
            }
        }
    }

    /**
     * Collect all package directories in root vendor (vendor/org/pkg paths).
     *
     * @return list<string> Relative paths like "psr/container"
     */
    private function collectVendorPackages(string $rootVendor): array
    {
        $packages = [];
        $skip = ['autoload.php', 'bin', 'composer'];

        foreach (scandir($rootVendor) as $vendor) {
            if ($vendor === '.' || $vendor === '..' || in_array($vendor, $skip, true)) {
                continue;
            }

            $vendorPath = $rootVendor . '/' . $vendor;

            if (! is_dir($vendorPath)) {
                continue;
            }

            foreach (scandir($vendorPath) as $pkg) {
                if ($pkg === '.' || $pkg === '..') {
                    continue;
                }

                $packages[] = $vendor . '/' . $pkg;
            }
        }

        return $packages;
    }

    /**
     * Create workspace vendor with symlinks to root vendor packages.
     *
     * @param list<string> $vendorPackages
     */
    private function symlinkVendor(string $rootVendor, string $workspaceVendor, array $vendorPackages): void
    {
        // Create vendor/composer dir for dump-autoload output
        if (! is_dir($workspaceVendor . '/composer')) {
            mkdir($workspaceVendor . '/composer', 0755, true);
        }

        // Copy installed.php for runtime InstalledVersions metadata
        $installedPhp = $rootVendor . '/composer/installed.php';

        if (file_exists($installedPhp)) {
            copy($installedPhp, $workspaceVendor . '/composer/installed.php');
        }

        // Remove stale symlinks (packages no longer in the scoped set)
        $scopedSet = array_flip($vendorPackages);
        $this->removeStaleSymlinks($workspaceVendor, $scopedSet);

        // Symlink each package dir
        foreach ($vendorPackages as $pkg) {
            $target = $rootVendor . '/' . $pkg;
            $link = $workspaceVendor . '/' . $pkg;

            if (file_exists($link) || is_link($link)) {
                continue;
            }

            $linkDir = dirname($link);

            if (! is_dir($linkDir)) {
                mkdir($linkDir, 0755, true);
            }

            symlink($target, $link);
        }

        // Copy bin proxies from root vendor
        $this->copyBinProxies($rootVendor, $workspaceVendor);
    }

    /**
     * Write a scoped installed.json containing only the resolved dependencies.
     *
     * @param array{packages: list<array<string, mixed>>, dev: bool, dev-package-names: list<string>} $fullData
     * @param array<string, int> $resolvedSet Package names as keys (from array_flip)
     */
    private function writeScopedInstalledJson(
        string $workspaceVendor,
        array $fullData,
        array $resolvedSet,
    ): void {
        $scoped = [
            'packages' => array_values(array_filter(
                $fullData['packages'],
                fn (array $pkg): bool => isset($resolvedSet[$pkg['name']]),
            )),
            'dev' => $fullData['dev'],
            'dev-package-names' => array_values(array_filter(
                $fullData['dev-package-names'],
                fn (string $name): bool => isset($resolvedSet[$name]),
            )),
        ];

        $json = json_encode($scoped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($workspaceVendor . '/composer/installed.json', $json);
    }

    /**
     * Remove symlinks in workspace vendor that are not in the scoped set.
     *
     * @param array<string, int> $scopedSet Package names as keys (from array_flip)
     */
    private function removeStaleSymlinks(string $workspaceVendor, array $scopedSet): void
    {
        $skip = ['autoload.php', 'bin', 'composer'];

        foreach (scandir($workspaceVendor) as $vendor) {
            if ($vendor === '.' || $vendor === '..' || in_array($vendor, $skip, true)) {
                continue;
            }

            $vendorPath = $workspaceVendor . '/' . $vendor;

            if (! is_dir($vendorPath)) {
                continue;
            }

            foreach (scandir($vendorPath) as $pkg) {
                if ($pkg === '.' || $pkg === '..') {
                    continue;
                }

                $fullName = $vendor . '/' . $pkg;
                $link = $workspaceVendor . '/' . $fullName;

                if (is_link($link) && ! isset($scopedSet[$fullName])) {
                    unlink($link);
                }
            }

            // Remove empty vendor directories
            $remaining = array_diff(scandir($vendorPath), ['.', '..']);

            if ($remaining === []) {
                rmdir($vendorPath);
            }
        }
    }

    private function copyBinProxies(string $rootVendor, string $workspaceVendor): void
    {
        $rootBinDir = $rootVendor . '/bin';

        if (! is_dir($rootBinDir)) {
            return;
        }

        $workspaceBinDir = $workspaceVendor . '/bin';

        if (! is_dir($workspaceBinDir)) {
            mkdir($workspaceBinDir, 0755, true);
        }

        foreach (scandir($rootBinDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $rootBinDir . '/' . $entry;
            $dest = $workspaceBinDir . '/' . $entry;

            if (file_exists($dest) || ! is_file($source)) {
                continue;
            }

            copy($source, $dest);
            chmod($dest, 0755);
        }
    }
}
