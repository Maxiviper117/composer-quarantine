<?php

declare(strict_types=1);

use Composer\IO\BufferIO;
use Composer\Package\CompletePackage;
use MaxiViper117\ComposerQuarantine\QuarantineConfig;
use MaxiViper117\ComposerQuarantine\QuarantineLogger;

it('logs when composer update or require starts', function (): void {
    $io = new BufferIO();
    $logger = new QuarantineLogger($io, new QuarantineConfig());

    $logger->reportCommand('update');

    expect($io->getOutput())->toContain('[Composer Quarantine]')
        ->and($io->getOutput())->toContain('composer update');
});

it('logs a startup banner for composer remove', function (): void {
    $io = new BufferIO();
    $logger = new QuarantineLogger($io, new QuarantineConfig());

    $logger->reportCommand('remove');

    expect($io->getOutput())->toContain('composer remove');
});

it('logs resolved policy details in verbose mode', function (): void {
    $io = new BufferIO();
    $logger = new QuarantineLogger($io, new QuarantineConfig(
        minimumAgeHours: 72,
        failOpen: false,
        ignoredPackages: ['symfony/*'],
        allowDev: true,
        verbose: true,
        allowPrerelease: true,
        packagistRequestIntervalMilliseconds: 250,
    ));

    $logger->reportCommand('require');

    expect($io->getOutput())->toContain('policy: minimum-age=72h')
        ->and($io->getOutput())->toContain('ignored=symfony/*')
        ->and($io->getOutput())->toContain('packagist-request-interval=250ms');
});

it('groups blocked versions for the same package into one compact line', function (): void {
    $io = new BufferIO();
    $logger = new QuarantineLogger($io, new QuarantineConfig());

    $logger->reportBlocked([
        ['package' => new CompletePackage('guzzlehttp/guzzle', '7.10.4', '7.10.4'), 'releaseDate' => new DateTimeImmutable('2026-05-22 19:00 UTC')],
        ['package' => new CompletePackage('guzzlehttp/guzzle', '7.10.3', '7.10.3'), 'releaseDate' => new DateTimeImmutable('2026-05-20 22:59 UTC')],
        ['package' => new CompletePackage('guzzlehttp/guzzle', '7.10.2', '7.10.2'), 'releaseDate' => new DateTimeImmutable('2026-05-20 11:58 UTC')],
    ]);

    $output = $io->getOutput();

    expect($output)->toContain('blocked guzzlehttp/guzzle versions [7.10.4, 7.10.3, 7.10.2]')
        ->and(substr_count($output, 'blocked guzzlehttp/guzzle'))->toBe(1);
});
