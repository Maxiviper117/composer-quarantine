<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\DependencyResolver\Request;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\RootPackage;
use Composer\Plugin\PrePoolCreateEvent;
use MaxiViper117\ComposerQuarantine\ConfigResolver;
use MaxiViper117\ComposerQuarantine\ComposerQuarantinePlugin;

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
            'max-suggested-versions-to-show' => 7,
            'check-dependencies' => false,
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
        ->and($config->verbose)->toBeTrue()
        ->and($config->maxSuggestedVersionsToShow)->toBe(7)
        ->and($config->checkDependencies)->toBeFalse();
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
                'max-suggested-versions-to-show' => 9,
                'check-dependencies' => false,
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
            ->and($config->packagistRequestIntervalMilliseconds)->toBe(2000)
            ->and($config->maxSuggestedVersionsToShow)->toBe(9)
            ->and($config->checkDependencies)->toBeFalse();
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

it('does not quarantine the root package itself', function (): void {
    $composer = new Composer();
    $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
    $package->setExtra([
        'composer-quarantine' => [
            'minimum-age-hours' => 240,
        ],
    ]);
    $composer->setPackage($package);
    $composer->setConfig(new Config(false, ''));

    $plugin = new ComposerQuarantinePlugin();
    $plugin->activate($composer, new NullIO());

    $rootPackage = new CompletePackage('demo/root', 'dev-main', 'dev-main');
    $event = new PrePoolCreateEvent(
        'pre-pool-create',
        [],
        new Request(),
        [],
        [],
        [],
        [],
        [$rootPackage],
        []
    );

    $plugin->onPrePoolCreate($event);

    expect($event->getPackages())->toHaveCount(1)
        ->and($event->getPackages()[0]->getName())->toBe('demo/root');
});

it('does not quarantine the plugin package itself', function (): void {
    $composer = new Composer();
    $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
    $package->setExtra([
        'composer-quarantine' => [
            'minimum-age-hours' => 240,
        ],
    ]);
    $composer->setPackage($package);
    $composer->setConfig(new Config(false, ''));

    $plugin = new ComposerQuarantinePlugin();
    $plugin->activate($composer, new NullIO());

    $pluginPackage = new CompletePackage('maxiviper117/composer-quarantine', 'dev-main', 'dev-main');
    $event = new PrePoolCreateEvent(
        'pre-pool-create',
        [],
        new Request(),
        [],
        [],
        [],
        [],
        [$pluginPackage],
        []
    );

    $plugin->onPrePoolCreate($event);

    expect($event->getPackages())->toHaveCount(1)
        ->and($event->getPackages()[0]->getName())->toBe('maxiviper117/composer-quarantine');
});

it('writes a quarantine report for blocked package versions', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'composer-quarantine-report-' . uniqid('', true);
    mkdir($tempDir, 0777, true);
    $previousCwd = getcwd();
    chdir($tempDir);

    try {
        $composer = new Composer();
        $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
        $package->setExtra([
            'composer-quarantine' => [
                'allow-dev' => false,
            ],
        ]);
        $composer->setPackage($package);
        $composer->setConfig(new Config(false, ''));

        $io = new BufferIO();
        $plugin = new ComposerQuarantinePlugin();
        $plugin->activate($composer, $io);

        $reflection = new ReflectionClass($plugin);
        $currentCommand = $reflection->getProperty('currentCommand');
        $currentCommand->setValue($plugin, 'require');

        $blockedPackage = new CompletePackage('acme/demo', 'dev-main', 'dev-main');
        $event = new PrePoolCreateEvent(
            'pre-pool-create',
            [],
            new Request(),
            [],
            [],
            [],
            [],
            [$blockedPackage],
            []
        );

        $plugin->onPrePoolCreate($event);

        $reportPath = $tempDir . DIRECTORY_SEPARATOR . '.composer-quarantine.json';
        $report = json_decode((string) file_get_contents($reportPath), true);

        expect($event->getPackages())->toHaveCount(0)
            ->and($report['command'] ?? null)->toBe('require')
            ->and($report['packages'][0]['name'] ?? null)->toBe('acme/demo')
            ->and($report['packages'][0]['blocked'][0]['version'] ?? null)->toBe('dev-main')
            ->and($report['packages'][0]['suggested'] ?? [])->toBe([]);
    } finally {
        chdir($previousCwd ?: __DIR__);
        @unlink($tempDir . DIRECTORY_SEPARATOR . '.composer-quarantine.json');
        @rmdir($tempDir);
    }
});

it('skips platform packages without packagist lookups', function (): void {
    $composer = new Composer();
    $package = new RootPackage('demo/root', '1.0.0.0', '1.0.0');
    $package->setExtra([
        'composer-quarantine' => [
            'verbose' => true,
        ],
    ]);
    $composer->setPackage($package);
    $composer->setConfig(new Config(false, ''));

    $io = new BufferIO();
    $plugin = new ComposerQuarantinePlugin();
    $plugin->activate($composer, $io);

    $platformPackage = new CompletePackage('ext-json', '8.3.0', '8.3.0');
    $event = new PrePoolCreateEvent(
        'pre-pool-create',
        [],
        new Request(),
        [],
        [],
        [],
        [],
        [$platformPackage],
        []
    );

    $plugin->onPrePoolCreate($event);

    expect($event->getPackages())->toHaveCount(1)
        ->and($event->getPackages()[0]->getName())->toBe('ext-json')
        ->and($io->getOutput())->not->toContain('fetching Packagist metadata')
        ->and($io->getOutput())->not->toContain('checking ext-json');
});
