<?php

declare(strict_types=1);

namespace Auroro\Workspaces\Adapter;

final class GlobalConfigPathResolver
{
    public static function resolve(): string
    {
        $composerHome = trim((string) shell_exec('composer config --global home 2>/dev/null'));

        if ($composerHome !== '' && is_dir($composerHome)) {
            return $composerHome . '/config.json';
        }

        $xdgConfig = getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config');

        if (is_dir($xdgConfig . '/composer')) {
            return $xdgConfig . '/composer/config.json';
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE');

        return $home . '/.composer/config.json';
    }
}
