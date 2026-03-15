<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class WorkspaceConfig
{
    /**
     * @param list<string> $globs      Workspace glob patterns (e.g. ["packages/*"])
     * @param string       $monorepo   Monorepo identity tag (e.g. "auroro")
     * @param string       $worktreeId Current worktree identifier (directory basename)
     * @param string       $rootDir    Absolute path to the worktree root
     * @param string|null  $graphPath  Additional output path for workspace.json (relative to rootDir)
     */
    public function __construct(
        public array $globs,
        public string $monorepo,
        public string $worktreeId,
        public string $rootDir,
        public ?string $graphPath = null,
    ) {}

    /** @return list<string> */
    public function resolvedUrls(): array
    {
        return array_map(
            fn (string $glob): string => $this->rootDir . '/' . $glob,
            $this->globs,
        );
    }
}
