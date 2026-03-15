<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Adapter;

use Auroro\Workspaces\GlobalConfigRepository;
use Auroro\Workspaces\LinkedEntry;

final class JsonGlobalConfigRepository implements GlobalConfigRepository
{
    public function __construct(
        private readonly string $configPath,
    ) {}

    public function entries(): array
    {
        $config = $this->readConfig();
        $repos = $config['repositories'] ?? [];

        if (! is_array($repos)) {
            return [];
        }

        return array_map(
            fn (array $repo): LinkedEntry => LinkedEntry::fromArray($repo),
            array_values($repos),
        );
    }

    public function save(array $entries): void
    {
        $config = $this->readConfig();

        if ($entries === []) {
            unset($config['repositories']);
        } else {
            $config['repositories'] = array_map(
                fn (LinkedEntry $e): array => $e->toArray(),
                $entries,
            );
        }

        $this->writeConfig($config);
    }

    public function path(): string
    {
        return $this->configPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        if (! is_file($this->configPath)) {
            return [];
        }

        $content = file_get_contents($this->configPath);

        if ($content === false) {
            return [];
        }

        $config = json_decode($content, true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $dir = dirname($this->configPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $encoded = json_encode(
            $this->ensureJsonObjects($config),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        file_put_contents($this->configPath, $encoded . "\n");
    }

    /**
     * Recursively ensure that associative-like keys serialize as JSON objects.
     * Handles the case where empty arrays like "config": {} would otherwise
     * serialize as "config": [].
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function ensureJsonObjects(array $config): array
    {
        $objectKeys = ['config', 'extra', 'options', 'require', 'require-dev', 'autoload', 'autoload-dev', 'scripts'];

        foreach ($config as $key => &$value) {
            if (is_array($value)) {
                if ($value === [] && in_array($key, $objectKeys, true)) {
                    $value = new \stdClass();
                } elseif (! array_is_list($value)) {
                    /** @var array<string, mixed> $value */
                    $value = $this->ensureJsonObjects($value);
                }
            }
        }

        return $config;
    }
}
