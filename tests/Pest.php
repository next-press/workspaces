<?php

declare(strict_types=1);

function composerInstance(array $extra = [], ?string $vendorDir = null): array
{
    $composer = new Composer\Composer();
    $config = new Composer\Config(false);
    $config->merge(['config' => [
        'vendor-dir' => $vendorDir ?? sys_get_temp_dir() . '/ws-test-' . uniqid(),
        'home' => sys_get_temp_dir() . '/ws-home-' . uniqid(),
    ]]);
    $composer->setConfig($config);

    $rootPackage = new Composer\Package\RootPackage('test/root', '1.0.0.0', '1.0.0');
    $rootPackage->setExtra($extra);
    $composer->setPackage($rootPackage);

    $io = new Composer\IO\BufferIO();

    return [$composer, $io];
}
