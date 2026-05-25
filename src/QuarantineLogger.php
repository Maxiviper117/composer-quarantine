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

    /**
     * @param array<int, array{package: PackageInterface, releaseDate: DateTimeImmutable|null}> $blocked
     */
    public function reportBlocked(array $blocked): void
    {
        foreach ($blocked as $item) {
            $package = $item['package'];
            $releaseDate = $item['releaseDate'];

            if ($this->config->verbose) {
                $this->io->writeError(sprintf(
                    "\n[Composer Quarantine]\n\nBlocked package:\n  Package:      %s\n  Version:      %s\n  Released:     %s\n  Age:          %s\n  Policy:       minimum-age=%dh\n\nReason:\n  Newly published packages are temporarily quarantined\n  to reduce supply chain attack exposure.\n",
                    $package->getName(),
                    $package->getPrettyVersion(),
                    $releaseDate instanceof DateTimeImmutable ? $this->formatUtc($releaseDate) : 'unknown',
                    $releaseDate instanceof DateTimeImmutable ? $this->formatAge($releaseDate) : 'unknown',
                    $this->config->minimumAgeHours,
                ));
                continue;
            }

            $this->io->writeError(sprintf(
                '<warning>[Composer Quarantine]</warning> blocked %s %s (released %s, age %s, minimum-age=%dh)',
                $package->getName(),
                $package->getPrettyVersion(),
                $releaseDate instanceof DateTimeImmutable ? $this->formatUtc($releaseDate) : 'unknown',
                $releaseDate instanceof DateTimeImmutable ? $this->formatAge($releaseDate) : 'unknown',
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
}
