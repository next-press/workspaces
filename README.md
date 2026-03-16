# Auroro Workspaces

Composer plugin for PHP monorepo workspace management. Part of the Auroro framework ecosystem.

Handles cascading installs with symlinked vendors, dependency-aware parallel command execution, dependency graph generation, and cross-worktree package linking.

## Installation

```bash
composer require auroro/workspaces
```

## Configuration

Add workspace paths to your root `composer.json`:

```json
{
    "extra": {
        "workspaces": {
            "paths": ["packages/*", "apps/*"]
        }
    },
    "config": {
        "allow-plugins": {
            "auroro/workspaces": true
        }
    }
}
```

The `paths` array defines glob patterns for workspace package directories. Defaults to `["packages/*"]` if omitted.

### Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `paths` | `list<string>` | `["packages/*"]` | Glob patterns for workspace package directories |
| `graph` | `string\|null` | `null` | Path to write the dependency graph (relative to root) |
| `autolink` | `bool` | `false` | Automatically link/unlink packages on install/uninstall |

### Dependency graph output

```json
{
    "extra": {
        "workspaces": {
            "paths": ["packages/*", "apps/*"],
            "graph": ".github/workspace.json"
        }
    }
}
```

The graph is always written to `vendor/workspace.json`. The `graph` option writes an additional copy to the specified path (relative to the project root).

### Automatic linking

By default, the plugin does **not** automatically link or unlink workspace packages. This means you must run `composer link` manually after installing to register packages as path repositories in Composer's global config.

To have this happen automatically on `composer install` and `composer uninstall`, set `autolink` to `true`:

```json
{
    "extra": {
        "workspaces": {
            "paths": ["packages/*", "apps/*"],
            "autolink": true
        }
    }
}
```

You can always link/unlink manually with `composer link` and `composer unlink` regardless of this setting.

## What happens on `composer install`

Two things happen automatically after every `composer install` or `composer update`:

1. **Dependency graph** — scans all workspace packages, builds an internal dependency graph, and writes it to `vendor/workspace.json` (and to the configured `graph` path if set).

2. **Cascading vendor install** — for workspace packages that have a `bin` entry or a `composer.lock`, creates a `vendor/` directory with symlinks to root vendor packages, copies Composer metadata and bin proxies, then runs `composer dump-autoload` in parallel. No duplicate downloads — everything points back to the root vendor.

If `autolink` is enabled, workspace linking also runs automatically (see above).

## Commands

### `composer each <script>`

Run a Composer script defined in each workspace package's `composer.json`. Packages that don't define the script are skipped.

```bash
composer each test          # run "test" script in all packages that define it
composer each build         # run "build" script
```

### `composer each -- <command>`

Run an arbitrary shell command in every workspace package directory.

```bash
composer each -- ls -la             # list files in each package
composer each -- git status         # check git status per package
```

### Filtering

Use `--filter` / `-f` to select packages by glob pattern. Patterns match the short name (after `/`) by default. Include `/` in the pattern to match the full package name.

```bash
composer each --filter 'phpx*' test           # packages matching phpx*
composer each --filter '*' --filter '!docs' test  # all except docs
composer each -f 'auroro/cl*' -- echo hello   # full name matching
```

Prefix with `!` to exclude.

### Execution order

Commands run in **topological levels** — packages at the same dependency level execute in parallel, but each level waits for the previous one to complete. This ensures dependencies are always satisfied before dependents run.

### `composer link`

Manually re-link the current worktree's packages to Composer's global config. This happens automatically on install, but can be run explicitly after switching worktrees.

### `composer unlink [--all]`

Remove the current worktree's entries from global config. Use `--all` to remove all worktrees for the monorepo.

### `composer workspaces`

Show all linked worktrees for the monorepo, highlighting the current one.

## Dependency graph

Written to `vendor/workspace.json` on every install/update (and to the `graph` path if configured):

```json
{
    "packages": {
        "auroro/result": {
            "path": "packages/result",
            "dependencies": []
        },
        "auroro/bus": {
            "path": "packages/bus",
            "dependencies": ["auroro/result"]
        }
    },
    "topological_levels": [
        ["auroro/code", "auroro/epoch", "auroro/result"],
        ["auroro/bus", "auroro/schema"],
        ["auroro/clip", "auroro/capsule"]
    ]
}
```

Each level contains packages that can be processed in parallel. Levels are ordered so that all dependencies of level N appear in levels 0..N-1.

## Cascading vendor install

Workspace packages with a `bin` entry or `composer.lock` get their own `vendor/` directory automatically:

- Package directories are **symlinked** from root vendor (no disk duplication)
- Composer's `installed.php` and `installed.json` are copied for autoloader generation
- Bin proxies are **copied** (not symlinked) so `__DIR__` resolves to the workspace's own vendor
- `composer dump-autoload` runs in parallel for all workspaces

This means `apps/skeleton/vendor/bin/phpx` works exactly as if you ran `composer install` in that directory — but without downloading anything twice.

## Cross-worktree linking

When working with multiple git worktrees of the same monorepo, each worktree can link its packages to Composer's global config. This makes workspace packages available as path repositories to any Composer project on the machine.

```bash
# In worktree auroro/main
composer link        # registers packages/* as path repos

# In worktree auroro/feature-x
composer link        # also registers its packages/*

# Both are now available — Composer resolves from whichever matches
composer workspaces  # shows all linked worktrees
```

Global config is stored in `~/.composer/config.json` (or `$XDG_CONFIG_HOME/composer/config.json`).
