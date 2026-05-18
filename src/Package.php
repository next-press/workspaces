<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

use Auroro\Composer\ScriptResolver;

final readonly class Package
{
    /**
     * @param list<string> $dependencies Internal workspace dependencies
     * @param array<string, string|list<string>> $scripts Composer scripts
     * @param list<string> $bin Bin entry points
     * @param array{psr-4?: array<string, string>, files?: list<string>} $autoload
     * @param array{psr-4?: array<string, string>} $autoloadDev
     * @param array<string, string> $requireDev
     * @param array<string, string> $require Raw require entries with version constraints
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $dependencies = [],
        public array $scripts = [],
        public array $bin = [],
        public array $autoload = [],
        public array $autoloadDev = [],
        public array $requireDev = [],
        public array $require = [],
    ) {}

    public function hasScript(string $name): bool
    {
        return isset($this->scripts[$name]);
    }

    public function script(string $name): string|null
    {
        if (! isset($this->scripts[$name])) {
            return null;
        }

        $resolver = new ScriptResolver($this->scripts, $this->bin);

        return $resolver->resolve($this->scripts[$name]);
    }

    public function shortName(): string
    {
        $pos = strpos($this->name, '/');

        return $pos !== false ? substr($this->name, $pos + 1) : $this->name;
    }
}
