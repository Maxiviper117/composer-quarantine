<?php

declare(strict_types=1);

use MaxiViper117\ComposerQuarantine\QuarantineWrapper;

it('writes quarantine config into composer.json via init', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'composer-quarantine-init-' . uniqid('', true);
    mkdir($tempDir, 0777, true);
    $previousCwd = getcwd();
    chdir($tempDir);

    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
        'name' => 'demo/project',
        'type' => 'project',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $exitCode = (new QuarantineWrapper())->run([
            'composer-quarantine',
            'init',
            '--minimum-age-hours=72',
            '--no-fail-open',
            '--max-suggested-versions-to-show=12',
        ]);

        $decoded = json_decode((string) file_get_contents($tempDir . DIRECTORY_SEPARATOR . 'composer.json'), true);

        expect($exitCode)->toBe(0)
            ->and($decoded['name'] ?? null)->toBe('demo/project')
            ->and($decoded['extra']['composer-quarantine']['minimum-age-hours'] ?? null)->toBe(72)
            ->and($decoded['extra']['composer-quarantine']['fail-open'] ?? null)->toBeFalse()
            ->and($decoded['extra']['composer-quarantine']['max-suggested-versions-to-show'] ?? null)->toBe(12)
            ->and($decoded['extra']['composer-quarantine']['check-dependencies'] ?? null)->toBeFalse();
    } finally {
        chdir($previousCwd ?: __DIR__);
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'composer.json');
        @rmdir($tempDir);
    }
});
