<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Tests\Fixtures;

use Auroro\Workspaces\GlobalConfigRepository;
use Auroro\Workspaces\LinkedEntry;

final class InMemoryGlobalConfigRepository implements GlobalConfigRepository
{
    /** @var list<LinkedEntry> */
    private array $entries;

    /** @param list<LinkedEntry> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function save(array $entries): void
    {
        $this->entries = $entries;
    }

    public function path(): string
    {
        return '/dev/null';
    }
}
