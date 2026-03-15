<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class Package
{
    /**
     * @param list<string> $dependencies Internal workspace dependencies
     * @param array<string, string|list<string>> $scripts Composer scripts
     * @param list<string> $bin Bin entry points
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $dependencies = [],
        public array $scripts = [],
        public array $bin = [],
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

        $script = $this->scripts[$name];

        return is_array($script) ? implode(' && ', $script) : $script;
    }

    public function shortName(): string
    {
        $pos = strpos($this->name, '/');

        return $pos !== false ? substr($this->name, $pos + 1) : $this->name;
    }
}
