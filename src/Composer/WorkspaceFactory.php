<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

use Auroro\Workspaces\Adapter\GlobalConfigPathResolver;
use Auroro\Workspaces\Adapter\JsonGlobalConfigRepository;
use Auroro\Workspaces\WorkspaceConfig;
use Auroro\Workspaces\WorkspaceLinker;
use Composer\Composer;

final readonly class WorkspaceFactory
{
    public function __construct(
        public WorkspaceLinker $linker,
        public WorkspaceConfig $config,
    ) {}

    public static function create(Composer $composer): self
    {
        $configPath = GlobalConfigPathResolver::resolve();

        return self::build($composer, $configPath);
    }

    public static function createWithComposerHome(Composer $composer): self
    {
        /** @var string $home */
        $home = $composer->getConfig()->get('home');
        $configPath = $home . '/config.json';

        return self::build($composer, $configPath);
    }

    private static function build(Composer $composer, string $configPath): self
    {
        $extra = $composer->getPackage()->getExtra();
        /** @var array{paths: list<string>, graph?: string} $workspaces */
        $workspaces = $extra['workspaces'] ?? ['paths' => ['packages/*']];
        /** @var list<string> $globs */
        $globs = $workspaces['paths'] ?? ['packages/*'];
        $graphPath = $workspaces['graph'] ?? null;

        $name = $composer->getPackage()->getName();
        $vendor = explode('/', $name, 2)[0];

        $rootDir = self::resolveRootDir($composer);
        $worktreeId = basename($rootDir);

        $repository = new JsonGlobalConfigRepository($configPath);

        return new self(
            linker: new WorkspaceLinker($repository),
            config: new WorkspaceConfig(
                globs: $globs,
                monorepo: $vendor,
                worktreeId: $worktreeId,
                rootDir: $rootDir,
                graphPath: $graphPath,
            ),
        );
    }

    private static function resolveRootDir(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        if (is_string($vendorDir)) {
            $root = dirname($vendorDir);

            if (is_dir($root)) {
                return $root;
            }
        }

        return (string) getcwd();
    }
}
