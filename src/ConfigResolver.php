<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PreCommandRunEvent;
use JsonException;

final class ConfigResolver
{
    public function resolve(Composer $composer, IOInterface $io, ?PreCommandRunEvent $event = null): QuarantineConfig
    {
        $config = new QuarantineConfig();
        $config = $this->applyGlobalComposerOverrides($config);
        $config = $this->applyEnvOverrides($config);
        $config = $this->applyRootPackageOverrides($composer, $config);

        if ($event !== null && $this->shouldBypass($event)) {
            $config = new QuarantineConfig(
                minimumAgeHours: $config->minimumAgeHours,
                failOpen: $config->failOpen,
                ignoredPackages: $config->ignoredPackages,
                allowDev: $config->allowDev,
                verbose: $config->verbose,
                allowPrerelease: $config->allowPrerelease,
                packagistRequestIntervalMilliseconds: $config->packagistRequestIntervalMilliseconds,
                maxSuggestedVersionsToShow: $config->maxSuggestedVersionsToShow,
                checkDependencies: $config->checkDependencies,
                bypass: true,
            );
        }

        return $config;
    }

    public function resolveForWorkingDirectory(string $workingDirectory): QuarantineConfig
    {
        $config = new QuarantineConfig();
        $config = $this->applyGlobalComposerOverrides($config);
        $config = $this->applyEnvOverrides($config);
        $config = $this->applyProjectOverridesFromFile($workingDirectory . DIRECTORY_SEPARATOR . 'composer.json', $config);

        return $config;
    }

    private function applyGlobalComposerOverrides(QuarantineConfig $config): QuarantineConfig
    {
        $globalPolicy = $this->readGlobalPolicy();

        if ($globalPolicy === []) {
            return $config;
        }

        return $this->mergePolicy($config, $globalPolicy);
    }

    private function applyEnvOverrides(QuarantineConfig $config): QuarantineConfig
    {
        $minimumAgeHours = $this->readIntEnv('COMPOSER_QUARANTINE_MINIMUM_AGE_HOURS');
        $failOpen = $this->readBoolEnv('COMPOSER_QUARANTINE_FAIL_OPEN');
        $verbose = $this->readBoolEnv('COMPOSER_QUARANTINE_VERBOSE');
        $allowDev = $this->readBoolEnv('COMPOSER_QUARANTINE_ALLOW_DEV');
        $allowPrerelease = $this->readBoolEnv('COMPOSER_QUARANTINE_ALLOW_PRERELEASE');
        $packagistRequestIntervalMilliseconds = $this->readIntEnv('COMPOSER_QUARANTINE_PACKAGIST_REQUEST_INTERVAL_MS');
        $maxSuggestedVersionsToShow = $this->readIntEnv('COMPOSER_QUARANTINE_MAX_SUGGESTED_VERSIONS_TO_SHOW');
        $checkDependencies = $this->readBoolEnv('COMPOSER_QUARANTINE_CHECK_DEPENDENCIES');
        $ignoredPackages = $this->readListEnv('COMPOSER_QUARANTINE_IGNORED_PACKAGES');

        return new QuarantineConfig(
            minimumAgeHours: $minimumAgeHours ?? $config->minimumAgeHours,
            failOpen: $failOpen ?? $config->failOpen,
            ignoredPackages: $ignoredPackages ?? $config->ignoredPackages,
            allowDev: $allowDev ?? $config->allowDev,
            verbose: $verbose ?? $config->verbose,
            allowPrerelease: $allowPrerelease ?? $config->allowPrerelease,
            packagistRequestIntervalMilliseconds: $packagistRequestIntervalMilliseconds ?? $config->packagistRequestIntervalMilliseconds,
            maxSuggestedVersionsToShow: $maxSuggestedVersionsToShow ?? $config->maxSuggestedVersionsToShow,
            checkDependencies: $checkDependencies ?? $config->checkDependencies,
            bypass: $config->bypass,
        );
    }

    private function applyRootPackageOverrides(Composer $composer, QuarantineConfig $config): QuarantineConfig
    {
        $extra = $composer->getPackage()->getExtra();
        $policy = $extra['composer-quarantine'] ?? [];

        if (!is_array($policy)) {
            return $config;
        }

        return $this->mergePolicy($config, $policy);
    }

    private function applyProjectOverridesFromFile(string $composerJsonPath, QuarantineConfig $config): QuarantineConfig
    {
        if (!is_file($composerJsonPath)) {
            return $config;
        }

        try {
            $contents = file_get_contents($composerJsonPath);

            if ($contents === false || $contents === '') {
                return $config;
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $config;
        }

        $policy = $decoded['extra']['composer-quarantine'] ?? [];

        if (!is_array($policy)) {
            return $config;
        }

        return $this->mergePolicy($config, $policy);
    }

    private function shouldBypass(PreCommandRunEvent $event): bool
    {
        $input = $event->getInput();

        if (method_exists($input, 'hasParameterOption') && $input->hasParameterOption(['--ignore-quarantine'], true)) {
            return true;
        }

        if (isset($_SERVER['argv']) && in_array('--ignore-quarantine', $_SERVER['argv'], true)) {
            return true;
        }

        return false;
    }

    private function readIntEnv(string $name): ?int
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function readBoolEnv(string $name): ?bool
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return array<string, mixed>
     */
    private function readGlobalPolicy(): array
    {
        $homeDir = $this->resolveComposerHomeDir();

        if ($homeDir === null) {
            return [];
        }

        $composerJson = rtrim($homeDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson)) {
            return [];
        }

        try {
            $contents = file_get_contents($composerJson);

            if ($contents === false || $contents === '') {
                return [];
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $policy = $decoded['extra']['composer-quarantine'] ?? [];

        return is_array($policy) ? $policy : [];
    }

    private function resolveComposerHomeDir(): ?string
    {
        $method = new \ReflectionMethod(Factory::class, 'getHomeDir');

        return $method->invoke(null);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function mergePolicy(QuarantineConfig $config, array $policy): QuarantineConfig
    {
        return new QuarantineConfig(
            minimumAgeHours: isset($policy['minimum-age-hours']) ? (int) $policy['minimum-age-hours'] : $config->minimumAgeHours,
            failOpen: array_key_exists('fail-open', $policy) ? (bool) $policy['fail-open'] : $config->failOpen,
            ignoredPackages: isset($policy['ignored-packages']) && is_array($policy['ignored-packages']) ? array_values(array_map('strval', $policy['ignored-packages'])) : $config->ignoredPackages,
            allowDev: array_key_exists('allow-dev', $policy) ? (bool) $policy['allow-dev'] : $config->allowDev,
            verbose: array_key_exists('verbose', $policy) ? (bool) $policy['verbose'] : $config->verbose,
            allowPrerelease: array_key_exists('allow-prerelease', $policy) ? (bool) $policy['allow-prerelease'] : $config->allowPrerelease,
            packagistRequestIntervalMilliseconds: array_key_exists('packagist-request-interval-ms', $policy) ? (int) $policy['packagist-request-interval-ms'] : $config->packagistRequestIntervalMilliseconds,
            maxSuggestedVersionsToShow: array_key_exists('max-suggested-versions-to-show', $policy) ? (int) $policy['max-suggested-versions-to-show'] : $config->maxSuggestedVersionsToShow,
            checkDependencies: array_key_exists('check-dependencies', $policy) ? (bool) $policy['check-dependencies'] : $config->checkDependencies,
            bypass: $config->bypass,
        );
    }

    /**
     * @return list<string>|null
     */
    private function readListEnv(string $name): ?array
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        $items = array_filter(array_map('trim', preg_split('/[,\s]+/', $value) ?: []), static fn (string $item): bool => $item !== '');

        return array_values($items);
    }
}
