<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Composer;

final readonly class InjectResult
{
    public function __construct(
        public int $requireCount,
        public int $requireDevCount,
        public int $autoloadDevCount,
        public int $autoloadFilesCount = 0,
    ) {}
}
