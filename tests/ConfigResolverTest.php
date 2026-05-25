<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\RootPackage;
use MaxiViper117\ComposerQuarantine\ConfigResolver;

it('merges root package overrides into the default policy', function (): void {
    $composer = new Composer();
    $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
    $package->setExtra([
        'composer-quarantine' => [
            'minimum-age-hours' => 72,
            'fail-open' => false,
            'ignored-packages' => ['acme/*'],
            'allow-dev' => true,
            'allow-prerelease' => true,
            'verbose' => true,
        ],
    ]);
    $composer->setPackage($package);
    $composer->setConfig(new Config(false, ''));

    $config = (new ConfigResolver())->resolve($composer, new NullIO());

    expect($config->minimumAgeHours)->toBe(72)
        ->and($config->failOpen)->toBeFalse()
        ->and($config->ignoredPackages)->toBe(['acme/*'])
        ->and($config->allowDev)->toBeTrue()
        ->and($config->allowPrerelease)->toBeTrue()
        ->and($config->verbose)->toBeTrue();
});

it('merges global composer policy before project overrides', function (): void {
    $tempHome = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'composer-quarantine-' . uniqid('', true);
    mkdir($tempHome, 0777, true);
    file_put_contents($tempHome . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
        'extra' => [
            'composer-quarantine' => [
                'minimum-age-hours' => 24,
                'fail-open' => false,
                'ignored-packages' => ['global/*'],
                'allow-dev' => true,
                'allow-prerelease' => true,
                'verbose' => true,
                'packagist-request-interval-ms' => 2000,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $previousHome = getenv('COMPOSER_HOME');
    putenv('COMPOSER_HOME=' . $tempHome);

    try {
        $composer = new Composer();
        $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
        $composer->setPackage($package);
        $composer->setConfig(new Config(false, ''));

        $config = (new ConfigResolver())->resolve($composer, new NullIO());

        expect($config->minimumAgeHours)->toBe(24)
            ->and($config->failOpen)->toBeFalse()
            ->and($config->ignoredPackages)->toBe(['global/*'])
            ->and($config->allowDev)->toBeTrue()
            ->and($config->allowPrerelease)->toBeTrue()
            ->and($config->verbose)->toBeTrue()
            ->and($config->packagistRequestIntervalMilliseconds)->toBe(2000);
    } finally {
        if ($previousHome === false) {
            putenv('COMPOSER_HOME');
        } else {
            putenv('COMPOSER_HOME=' . $previousHome);
        }

        @unlink($tempHome . DIRECTORY_SEPARATOR . 'composer.json');
        @rmdir($tempHome);
    }
});
