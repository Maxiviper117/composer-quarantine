<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use DateTimeImmutable;
use DateTimeZone;

final class QuarantineLogger
{
    public function __construct(
        private readonly IOInterface $io,
        private readonly QuarantineConfig $config,
    ) {
    }

    public function reportCommand(string $command): void
    {
        $this->io->writeError(sprintf(
            '<info>[Composer Quarantine]</info> active during `composer %s`',
            $command
        ));

        if (!$this->config->verbose) {
            return;
        }

        $ignoredPackages = $this->config->ignoredPackages === [] ? 'none' : implode(', ', $this->config->ignoredPackages);

        $this->io->writeError(sprintf(
            '<info>[Composer Quarantine]</info> policy: minimum-age=%dh, fail-open=%s, allow-dev=%s, allow-prerelease=%s, ignored=%s, packagist-request-interval=%dms',
            $this->config->minimumAgeHours,
            $this->boolToText($this->config->failOpen),
            $this->boolToText($this->config->allowDev),
            $this->boolToText($this->config->allowPrerelease),
            $ignoredPackages,
            $this->config->packagistRequestIntervalMilliseconds,
        ));
    }

    /**
     * @param array<int, array{package: PackageInterface, releaseDate: DateTimeImmutable|null}> $blocked
     */
    public function reportBlocked(array $blocked): void
    {
        $grouped = [];

        foreach ($blocked as $item) {
            $package = $item['package'];
            $grouped[$package->getName()][] = [
                'version' => $package->getPrettyVersion(),
                'releaseDate' => $item['releaseDate'],
            ];
        }

        foreach ($grouped as $packageName => $entries) {
            $versions = array_map(static fn (array $entry): string => $entry['version'], $entries);
            $versionsText = implode(', ', array_values(array_unique($versions)));
            $newestReleaseDate = $this->newestReleaseDate($entries);

            if ($this->config->verbose) {
                $this->io->writeError(sprintf(
                    "\n[Composer Quarantine]\n\nBlocked package:\n  Package:      %s\n  Versions:     %s\n  Newest:       %s\n  Age:          %s\n  Policy:       minimum-age=%dh\n\nReason:\n  Newly published packages are temporarily quarantined\n  to reduce supply chain attack exposure.\n",
                    $packageName,
                    $versionsText,
                    $newestReleaseDate instanceof DateTimeImmutable ? $this->formatUtc($newestReleaseDate) : 'unknown',
                    $newestReleaseDate instanceof DateTimeImmutable ? $this->formatAge($newestReleaseDate) : 'unknown',
                    $this->config->minimumAgeHours,
                ));
                continue;
            }

            $this->io->writeError(sprintf(
                '<warning>[Composer Quarantine]</warning> blocked %s versions [%s] (minimum-age=%dh)',
                $packageName,
                $versionsText,
                $this->config->minimumAgeHours,
            ));
        }
    }

    public function warnFailOpen(string $packageName, string $reason): void
    {
        $this->io->writeError(sprintf(
            '<warning>[Composer Quarantine]</warning> %s: %s',
            $packageName,
            $reason
        ));
    }

    private function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i T');
    }

    private function formatAge(DateTimeImmutable $releaseDate): string
    {
        $seconds = max(0, time() - $releaseDate->getTimestamp());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%dh %dm', $hours, $minutes);
    }

    private function boolToText(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param array<int, array{version: string, releaseDate: DateTimeImmutable|null}> $entries
     */
    private function newestReleaseDate(array $entries): ?DateTimeImmutable
    {
        $newest = null;

        foreach ($entries as $entry) {
            $releaseDate = $entry['releaseDate'];

            if (!$releaseDate instanceof DateTimeImmutable) {
                continue;
            }

            if ($newest === null || $releaseDate > $newest) {
                $newest = $releaseDate;
            }
        }

        return $newest;
    }
}
