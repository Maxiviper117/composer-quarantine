<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Package\PackageInterface;
use DateTimeImmutable;

final class PackageAgeValidator
{
    public function __construct(private readonly QuarantineConfig $config)
    {
    }

    public function shouldBlockByAge(PackageInterface $package, DateTimeImmutable $releaseDate): bool
    {
        if ($this->config->bypass) {
            return false;
        }

        if ($this->config->isIgnored($package->getName())) {
            return false;
        }

        $ageSeconds = time() - $releaseDate->getTimestamp();

        return $ageSeconds < $this->config->minimumAgeSeconds();
    }

    public function shouldBlockByStability(PackageInterface $package): bool
    {
        if ($this->config->bypass) {
            return false;
        }

        if ($this->config->isIgnored($package->getName())) {
            return false;
        }

        if ($package->isDev()) {
            return !$this->config->allowDev;
        }

        return !$this->config->allowPrerelease && $this->isPreRelease($package->getStability());
    }

    private function isPreRelease(string $stability): bool
    {
        return in_array(strtolower($stability), ['alpha', 'beta', 'rc'], true);
    }
}
