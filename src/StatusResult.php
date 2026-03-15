<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class StatusResult
{
    /**
     * @param list<LinkedEntry> $entries
     */
    public function __construct(
        public array $entries,
        public string $currentWorktreeId,
        public string $configPath,
    ) {}
}
