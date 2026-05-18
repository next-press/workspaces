<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Auroro\Workspaces\Package;
use Auroro\Workspaces\WorkspaceConfig;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Semver\VersionParser;

final readonly class WorkspaceInjector
{
    public function __construct(
        private WorkspaceConfig $config,
    ) {}

    /**
     * @param list<Package> $packages
     */
    public function inject(RootPackageInterface $rootPackage, array $packages): InjectResult
    {
        $requireCount = $this->injectRequires($rootPackage, $packages);
        $requireDevCount = $this->injectRequireDev($rootPackage, $packages);
        $autoloadDevCount = $this->injectAutoloadDev($rootPackage, $packages);
        $autoloadFilesCount = $this->injectAutoloadFiles($rootPackage, $packages);

        return new InjectResult($requireCount, $requireDevCount, $autoloadDevCount, $autoloadFilesCount);
    }

    /**
     * @param list<Package> $packages
     */
    private function injectRequires(RootPackageInterface $rootPackage, array $packages): int
    {
        $requires = $rootPackage->getRequires();
        $existingNames = array_keys($requires);
        $parser = new VersionParser();
        $constraint = $parser->parseConstraints('*');
        $added = 0;

        foreach ($packages as $package) {
            if (in_array($package->name, $existingNames, true)) {
                continue;
            }

            $requires[$package->name] = new Link(
                source: $rootPackage->getName(),
                target: $package->name,
                constraint: $constraint,
                description: Link::TYPE_REQUIRE,
                prettyConstraint: '*',
            );
            $added++;
        }

        $rootPackage->setRequires($requires);

        return $added;
    }

    /**
     * @param list<Package> $packages
     */
    private function injectRequireDev(RootPackageInterface $rootPackage, array $packages): int
    {
        $devRequires = $rootPackage->getDevRequires();
        $existingNames = array_keys($devRequires);
        $knownPackages = array_map(fn (Package $p) => $p->name, $packages);
        $parser = new VersionParser();
        $added = 0;

        foreach ($packages as $package) {
            foreach ($package->requireDev as $name => $constraint) {
                // Skip workspace packages, php, and extensions
                if (in_array($name, $knownPackages, true)
                    || in_array($name, $existingNames, true)
                    || $name === 'php'
                    || str_starts_with($name, 'ext-')
                ) {
                    continue;
                }

                $devRequires[$name] = new Link(
                    source: $rootPackage->getName(),
                    target: $name,
                    constraint: $parser->parseConstraints($constraint),
                    description: Link::TYPE_DEV_REQUIRE,
                    prettyConstraint: $constraint,
                );
                $existingNames[] = $name;
                $added++;
            }
        }

        $rootPackage->setDevRequires($devRequires);

        return $added;
    }

    /**
     * @param list<Package> $packages
     */
    private function injectAutoloadDev(RootPackageInterface $rootPackage, array $packages): int
    {
        $devAutoload = $rootPackage->getDevAutoload();
        /** @var array<string, string> $psr4 */
        $psr4 = $devAutoload['psr-4'] ?? [];
        $added = 0;

        foreach ($packages as $package) {
            $explicitDev = $package->autoloadDev['psr-4'] ?? [];

            if ($explicitDev !== []) {
                foreach ($explicitDev as $namespace => $path) {
                    if (isset($psr4[$namespace])) {
                        continue;
                    }

                    $psr4[$namespace] = $package->path . '/' . $path;
                    $added++;
                }

                continue;
            }

            // Infer test namespace from autoload psr-4 when no autoload-dev declared
            foreach ($package->autoload['psr-4'] ?? [] as $namespace => $path) {
                $testNamespace = $namespace . 'Tests\\';

                if (isset($psr4[$testNamespace])) {
                    continue;
                }

                $testsDir = $this->config->rootDir . '/' . $package->path . '/tests';

                if (is_dir($testsDir)) {
                    $psr4[$testNamespace] = $package->path . '/tests/';
                    $added++;
                }
            }
        }

        $devAutoload['psr-4'] = $psr4;
        $rootPackage->setDevAutoload($devAutoload);

        return $added;
    }

    /**
     * @param list<Package> $packages
     */
    private function injectAutoloadFiles(RootPackageInterface $rootPackage, array $packages): int
    {
        $autoload = $rootPackage->getAutoload();
        /** @var list<string> $files */
        $files = $autoload['files'] ?? [];
        $existing = array_flip($files);
        $added = 0;

        foreach ($packages as $package) {
            foreach ($package->autoload['files'] ?? [] as $file) {
                $resolved = $package->path . '/' . $file;

                if (isset($existing[$resolved])) {
                    continue;
                }

                $files[] = $resolved;
                $existing[$resolved] = true;
                $added++;
            }
        }

        $autoload['files'] = $files;
        $rootPackage->setAutoload($autoload);

        return $added;
    }
}
