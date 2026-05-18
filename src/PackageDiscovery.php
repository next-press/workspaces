<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final class PackageDiscovery
{
    /**
     * @param list<string> $globs
     * @return list<Package>
     */
    public function discover(string $rootDir, array $globs): array
    {
        $raw = [];

        foreach ($globs as $glob) {
            $matches = glob($rootDir . '/' . $glob, GLOB_ONLYDIR);

            if ($matches === false) {
                continue;
            }

            foreach ($matches as $dir) {
                $composerFile = $dir . '/composer.json';

                if (! file_exists($composerFile)) {
                    continue;
                }

                $contents = file_get_contents($composerFile);

                if ($contents === false) {
                    continue;
                }

                $data = json_decode($contents, true);

                if (! is_array($data) || ! isset($data['name'])) {
                    continue;
                }

                $relativePath = ltrim(substr($dir, strlen($rootDir)), '/');

                /** @var list<string> $requires */
                $requires = array_keys($data['require'] ?? []);

                /** @var array<string, string|list<string>> $scripts */
                $scripts = $data['scripts'] ?? [];

                /** @var list<string> $bin */
                $bin = $data['bin'] ?? [];
                $bin = is_string($bin) ? [$bin] : $bin;

                /** @var array{psr-4?: array<string, string>, files?: list<string>} $autoload */
                $autoload = $data['autoload'] ?? [];

                /** @var array{psr-4?: array<string, string>} $autoloadDev */
                $autoloadDev = $data['autoload-dev'] ?? [];

                /** @var array<string, string> $requireDev */
                $requireDev = $data['require-dev'] ?? [];

                /** @var array<string, string> $require */
                $require = $data['require'] ?? [];

                $raw[] = [
                    'name' => (string) $data['name'],
                    'path' => $relativePath,
                    'requires' => $requires,
                    'scripts' => $scripts,
                    'bin' => $bin,
                    'autoload' => $autoload,
                    'autoloadDev' => $autoloadDev,
                    'requireDev' => $requireDev,
                    'require' => $require,
                ];
            }
        }

        $knownNames = array_column($raw, 'name');

        return array_map(
            /** @param array{name: string, path: string, requires: list<string>, scripts: array<string, string|list<string>>, bin: list<string>, autoload: array{psr-4?: array<string, string>, files?: list<string>}, autoloadDev: array{psr-4?: array<string, string>}, requireDev: array<string, string>, require: array<string, string>} $entry */
            fn (array $entry) => new Package(
                name: $entry['name'],
                path: $entry['path'],
                dependencies: array_values(array_intersect($entry['requires'], $knownNames)),
                scripts: $entry['scripts'],
                bin: $entry['bin'],
                autoload: $entry['autoload'],
                autoloadDev: $entry['autoloadDev'],
                requireDev: $entry['requireDev'],
                require: $entry['require'],
            ),
            $raw,
        );
    }
}
