<?php

declare(strict_types=1);

namespace Auroro\Workspaces;

final readonly class WorkspaceLinker
{
    public function __construct(
        private GlobalConfigRepository $config,
    ) {}

    public function link(WorkspaceConfig $workspace): LinkResult
    {
        $entries = $this->config->entries();

        // Remove existing entries for this worktree (idempotent)
        $entries = array_values(array_filter(
            $entries,
            fn (LinkedEntry $e): bool => ! $e->belongsToWorktree($workspace->monorepo, $workspace->worktreeId),
        ));

        // Add one entry per resolved URL
        foreach ($workspace->resolvedUrls() as $url) {
            $entries[] = new LinkedEntry(
                url: $url,
                monorepo: $workspace->monorepo,
                worktree: $workspace->worktreeId,
            );
        }

        $this->config->save($entries);

        return new LinkResult(
            worktreeId: $workspace->worktreeId,
            urls: $workspace->resolvedUrls(),
            configPath: $this->config->path(),
        );
    }

    public function unlink(WorkspaceConfig $workspace, bool $all = false): UnlinkResult
    {
        $entries = $this->config->entries();
        $originalCount = count($entries);

        $entries = array_values(array_filter(
            $entries,
            fn (LinkedEntry $e): bool => $all
                ? ! $e->belongsTo($workspace->monorepo)
                : ! $e->belongsToWorktree($workspace->monorepo, $workspace->worktreeId),
        ));

        $this->config->save($entries);

        return new UnlinkResult(
            worktreeId: $workspace->worktreeId,
            removedCount: $originalCount - count($entries),
            all: $all,
            configPath: $this->config->path(),
        );
    }

    public function status(WorkspaceConfig $workspace): StatusResult
    {
        $entries = array_values(array_filter(
            $this->config->entries(),
            fn (LinkedEntry $e): bool => $e->belongsTo($workspace->monorepo),
        ));

        return new StatusResult(
            entries: $entries,
            currentWorktreeId: $workspace->worktreeId,
            configPath: $this->config->path(),
        );
    }
}
