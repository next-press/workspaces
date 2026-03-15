<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class LinkedEntry
{
    public function __construct(
        public string $url,
        public string $monorepo,
        public string $worktree,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => 'path',
            'url' => $this->url,
            'canonical' => false,
            'options' => [
                'symlink' => true,
                'monorepo' => $this->monorepo,
                'worktree' => $this->worktree,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            monorepo: (string) ($data['options']['monorepo'] ?? ''),
            worktree: (string) ($data['options']['worktree'] ?? ''),
        );
    }

    public function belongsTo(string $monorepo): bool
    {
        return $this->monorepo === $monorepo;
    }

    public function belongsToWorktree(string $monorepo, string $worktreeId): bool
    {
        return $this->belongsTo($monorepo) && $this->worktree === $worktreeId;
    }
}
