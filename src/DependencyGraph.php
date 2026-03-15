<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class DependencyGraph
{
    /** @var array<string, Package> */
    private array $indexed;

    /** @param list<Package> $packages */
    public function __construct(private array $packages)
    {
        $indexed = [];

        foreach ($packages as $package) {
            $indexed[$package->name] = $package;
        }

        $this->indexed = $indexed;
    }

    /** @return list<Package> */
    public function packages(): array
    {
        return $this->packages;
    }

    /** @return list<string> */
    public function topologicalOrder(): array
    {
        $inDegree = [];
        $dependents = [];

        foreach ($this->indexed as $name => $package) {
            $inDegree[$name] ??= 0;
            $dependents[$name] ??= [];

            foreach ($package->dependencies as $dep) {
                if (! isset($this->indexed[$dep])) {
                    continue;
                }

                $inDegree[$name]++;
                $dependents[$dep][] = $name;
            }
        }

        ksort($inDegree);

        $queue = [];

        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $order = [];

        while ($queue !== []) {
            sort($queue);
            $current = array_shift($queue);
            $order[] = $current;

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;

                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        return $order;
    }

    /** @return list<list<Package>> */
    public function topologicalLevels(): array
    {
        $inDegree = [];
        $dependents = [];

        foreach ($this->indexed as $name => $package) {
            $inDegree[$name] ??= 0;
            $dependents[$name] ??= [];

            foreach ($package->dependencies as $dep) {
                if (! isset($this->indexed[$dep])) {
                    continue;
                }

                $inDegree[$name]++;
                $dependents[$dep][] = $name;
            }
        }

        ksort($inDegree);

        $queue = [];

        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $levels = [];

        while ($queue !== []) {
            sort($queue);

            $level = [];

            foreach ($queue as $name) {
                $level[] = $this->indexed[$name];
            }

            $levels[] = $level;

            $next = [];

            foreach ($queue as $current) {
                foreach ($dependents[$current] as $dependent) {
                    $inDegree[$dependent]--;

                    if ($inDegree[$dependent] === 0) {
                        $next[] = $dependent;
                    }
                }
            }

            $queue = $next;
        }

        return $levels;
    }

    public function filter(PackageMatcher $matcher): self
    {
        return new self(array_values(
            array_filter($this->packages, fn (Package $p) => $matcher->matches($p)),
        ));
    }

    /** @return array{packages: array<string, array{path: string, dependencies: list<string>}>, topological_levels: list<list<string>>} */
    public function toArray(): array
    {
        $packages = [];

        foreach ($this->indexed as $name => $package) {
            $packages[$name] = [
                'path' => $package->path,
                'dependencies' => $package->dependencies,
            ];
        }

        $levels = array_map(
            fn (array $level) => array_map(fn (Package $p) => $p->name, $level),
            $this->topologicalLevels(),
        );

        return [
            'packages' => $packages,
            'topological_levels' => $levels,
        ];
    }
}
