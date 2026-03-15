<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class LinkResult
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $worktreeId,
        public array $urls,
        public string $configPath,
    ) {}
}
