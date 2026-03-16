<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Auroro\Workspaces\DependencyGraph;
use Auroro\Workspaces\Package;
use Auroro\Workspaces\PackageDiscovery;
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

        $extra = $composer->getPackage()->getExtra();
        if (!($extra['workspaces']['autolink'] ?? false)) {
            return;
        }

        $factory = WorkspaceFactory::createWithComposerHome($composer);
        $factory->linker->link($factory->config);
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

        $this->io->write('');
        $this->io->write('<comment>Workspaces:</comment> installing vendors...');

        // Collect vendor package dirs to symlink (skip composer/, bin/, autoload.php)
        $vendorPackages = $this->collectVendorPackages($rootVendor);

        // Process each workspace in parallel: symlink + dump-autoload
        $processes = [];

        foreach ($targets as $package) {
            $workspaceVendor = $rootDir . '/' . $package->path . '/vendor';

            $this->symlinkVendor($rootVendor, $workspaceVendor, $vendorPackages);

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

        // Copy composer metadata so dump-autoload knows what's available
        foreach (['installed.php', 'installed.json'] as $file) {
            $source = $rootVendor . '/composer/' . $file;

            if (file_exists($source)) {
                copy($source, $workspaceVendor . '/composer/' . $file);
            }
        }

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
