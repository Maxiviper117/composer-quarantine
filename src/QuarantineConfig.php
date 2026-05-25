<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

final readonly class QuarantineConfig
{
    /**
     * @param list<string> $ignoredPackages
     */
    public function __construct(
        public int $minimumAgeHours = 48,
        public bool $failOpen = true,
        public array $ignoredPackages = [],
        public bool $allowDev = false,
        public bool $verbose = false,
        public bool $allowPrerelease = false,
        public int $packagistRequestIntervalMilliseconds = 1000,
        public int $maxSuggestedVersionsToShow = 20,
        public bool $checkDependencies = true,
        public bool $bypass = false,
    ) {
    }

    public function minimumAgeSeconds(): int
    {
        return max(0, $this->minimumAgeHours) * 3600;
    }

    public function isIgnored(string $packageName): bool
    {
        $packageName = strtolower($packageName);

        foreach ($this->ignoredPackages as $pattern) {
            $pattern = strtolower($pattern);

            $flags = defined('FNM_CASEFOLD') ? FNM_CASEFOLD : 0;

            if ($pattern === $packageName || fnmatch($pattern, $packageName, $flags)) {
                return true;
            }
        }

        return false;
    }
}
