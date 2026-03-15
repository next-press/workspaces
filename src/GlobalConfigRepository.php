<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

interface GlobalConfigRepository
{
    /** @return list<LinkedEntry> */
    public function entries(): array;

    /** @param list<LinkedEntry> $entries */
    public function save(array $entries): void;

    public function path(): string;
}
