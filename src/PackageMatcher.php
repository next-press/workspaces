<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class PackageMatcher
{
    /** @var list<string> */
    private array $includes;

    /** @var list<string> */
    private array $excludes;

    /** @param list<string> $patterns */
    public function __construct(array $patterns = [])
    {
        $includes = [];
        $excludes = [];

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                $excludes[] = substr($pattern, 1);
            } else {
                $includes[] = $pattern;
            }
        }

        $this->includes = $includes;
        $this->excludes = $excludes;
    }

    public function matches(Package $package): bool
    {
        if ($this->excludes !== [] && $this->matchesAny($package, $this->excludes)) {
            return false;
        }

        if ($this->includes === []) {
            return true;
        }

        return $this->matchesAny($package, $this->includes);
    }

    /** @param list<string> $patterns */
    private function matchesAny(Package $package, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $subject = str_contains($pattern, '/') ? $package->name : $package->shortName();

            if (fnmatch($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }
}
