<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Package\PackageInterface;
use DateTimeImmutable;
use DateTimeZone;

final class QuarantineReportWriter
{
    private const REPORT_FILE = '.composer-quarantine.json';

    /**
     * @param array<string, array{packageName: string, blocked: array<int, array{version: string, releaseDate: DateTimeImmutable|null}>, allowed: array<int, string>}> $packages
     */
    public function write(string $workingDirectory, string $command, array $packages): void
    {
        $payload = [
            'generated-at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'command' => $command,
            'packages' => [],
        ];

        foreach ($packages as $package) {
            if ($package['blocked'] === []) {
                continue;
            }

            $payload['packages'][] = [
                'name' => $package['packageName'],
                'blocked' => array_map(
                    static fn (array $entry): array => [
                        'version' => $entry['version'],
                        'released' => $entry['releaseDate'] instanceof DateTimeImmutable ? $entry['releaseDate']->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM) : null,
                    ],
                    $package['blocked']
                ),
                'suggested' => array_values($package['allowed']),
            ];
        }

        if ($payload['packages'] === []) {
            $this->delete($workingDirectory);
            return;
        }

        file_put_contents($workingDirectory . DIRECTORY_SEPARATOR . self::REPORT_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function path(string $workingDirectory): string
    {
        return $workingDirectory . DIRECTORY_SEPARATOR . self::REPORT_FILE;
    }

    public function exists(string $workingDirectory): bool
    {
        return is_file($this->path($workingDirectory));
    }

    public function delete(string $workingDirectory): void
    {
        $path = $this->path($workingDirectory);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
