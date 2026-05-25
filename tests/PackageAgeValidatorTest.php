<?php

declare(strict_types=1);

use Composer\Package\CompletePackage;
use MaxiViper117\ComposerQuarantine\PackageAgeValidator;
use MaxiViper117\ComposerQuarantine\QuarantineConfig;

it('blocks fresh stable packages by age', function (): void {
    $config = new QuarantineConfig(minimumAgeHours: 48);
    $validator = new PackageAgeValidator($config);
    $package = new CompletePackage('symfony/console', '7.3.0', '7.3.0');

    expect($validator->shouldBlockByAge($package, new DateTimeImmutable('+2 hours')))->toBeTrue();
});

it('allows old stable packages', function (): void {
    $config = new QuarantineConfig(minimumAgeHours: 48);
    $validator = new PackageAgeValidator($config);
    $package = new CompletePackage('symfony/console', '7.2.0', '7.2.0');

    expect($validator->shouldBlockByAge($package, new DateTimeImmutable('-72 hours')))->toBeFalse();
});

it('bypasses ignored packages', function (): void {
    $config = new QuarantineConfig(minimumAgeHours: 48, ignoredPackages: ['symfony/*']);
    $validator = new PackageAgeValidator($config);
    $package = new CompletePackage('symfony/console', '7.3.0', '7.3.0');

    expect($validator->shouldBlockByAge($package, new DateTimeImmutable('+1 hour')))->toBeFalse();
});

it('blocks dev packages when allow-dev is disabled', function (): void {
    $config = new QuarantineConfig(allowDev: false);
    $validator = new PackageAgeValidator($config);
    $package = new CompletePackage('acme/demo', 'dev-main', 'dev-main');

    expect($validator->shouldBlockByStability($package))->toBeTrue();
});
