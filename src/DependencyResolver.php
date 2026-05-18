<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class DependencyResolver
{
    /**
     * @param array<string, list<string>> $requireMap Package name → list of require dependency names
     */
    public function __construct(
        private array $requireMap,
    ) {}

    /**
     * @param array{packages: list<array{name: string, require?: array<string, string>}>} $installedJson
     */
    public static function fromInstalledJson(array $installedJson): self
    {
        $map = [];

        foreach ($installedJson['packages'] as $package) {
            $map[$package['name']] = array_keys($package['require'] ?? []);
        }

        return new self($map);
    }

    /**
     * Compute the transitive dependency closure for the given seed package names.
     *
     * @param list<string> $seeds
     * @return list<string> Sorted list of all transitively required package names
     */
    public function resolve(array $seeds): array
    {
        $visited = [];
        $queue = $seeds;

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($visited[$name])) {
                continue;
            }

            if ($this->isPlatformPackage($name)) {
                continue;
            }

            $visited[$name] = true;

            foreach ($this->requireMap[$name] ?? [] as $dep) {
                if (! isset($visited[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        $result = array_keys($visited);
        sort($result);

        return $result;
    }

    private function isPlatformPackage(string $name): bool
    {
        return $name === 'php'
            || str_starts_with($name, 'ext-')
            || $name === 'composer-plugin-api'
            || $name === 'composer-runtime-api';
    }
}
