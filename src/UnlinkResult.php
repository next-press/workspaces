<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class UnlinkResult
{
    public function __construct(
        public string $worktreeId,
        public int $removedCount,
        public bool $all,
        public string $configPath,
    ) {}
}
