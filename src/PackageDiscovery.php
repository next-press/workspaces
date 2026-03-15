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

                $raw[] = [
                    'name' => (string) $data['name'],
                    'path' => $relativePath,
                    'requires' => $requires,
                    'scripts' => $scripts,
                    'bin' => $bin,
                ];
            }
        }

        $knownNames = array_column($raw, 'name');

        return array_map(
            /** @param array{name: string, path: string, requires: list<string>, scripts: array<string, string|list<string>>, bin: list<string>} $entry */
            fn (array $entry) => new Package(
                name: $entry['name'],
                path: $entry['path'],
                dependencies: array_values(array_intersect($entry['requires'], $knownNames)),
                scripts: $entry['scripts'],
                bin: $entry['bin'],
            ),
            $raw,
        );
    }
}
