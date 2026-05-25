<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Util\HttpDownloader;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ComposerQuarantinePlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'maxiviper117/composer-quarantine';

    private Composer $composer;
    private IOInterface $io;
    private QuarantineConfig $config;
    private string $currentCommand = '';
    private CacheStore $cache;
    private RateLimiter $rateLimiter;
    private PackagistMetadataFetcher $fetcher;
    private QuarantineReportWriter $reportWriter;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->cache = new CacheStore();
        $this->config = (new ConfigResolver())->resolve($composer, $io);
        $this->rateLimiter = $this->createRateLimiter();
        $this->fetcher = $this->createFetcher();
        $this->reportWriter = new QuarantineReportWriter();
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_COMMAND_RUN => ['onPreCommandRun', 0],
            PluginEvents::PRE_POOL_CREATE => ['onPrePoolCreate', 0],
        ];
    }

    public function onPreCommandRun(PreCommandRunEvent $event): void
    {
        if ($this->config->bypass) {
            return;
        }

        $this->currentCommand = $event->getCommand();

        $resolver = new ConfigResolver();
        $this->config = $resolver->resolve($this->composer, $this->io, $event);
        $this->rateLimiter = $this->createRateLimiter();
        $this->fetcher = $this->createFetcher();

        if ($this->shouldLogCommand($event->getCommand())) {
            (new QuarantineLogger($this->io, $this->config))->reportCommand($event->getCommand());
        }
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        if ($this->config->bypass || $this->currentCommand === 'remove') {
            return;
        }

        $rootPackageName = $this->composer->getPackage()->getName();
        $packages = $event->getPackages();
        $fixedPackages = $event->getUnacceptableFixedPackages();
        $validator = new PackageAgeValidator($this->config);
        $logger = new QuarantineLogger($this->io, $this->config);
        $filteredPackages = [];
        $blocked = [];
        $report = [];

        foreach ($packages as $package) {
            if (!$package instanceof PackageInterface) {
                continue;
            }

            if ($this->isRootPackage($package, $rootPackageName)) {
                $filteredPackages[] = $package;
                $this->trackReportEntry($report, $package, false, null);
                continue;
            }

            if ($this->isSelfPackage($package)) {
                $filteredPackages[] = $package;
                $this->trackReportEntry($report, $package, false, null);
                continue;
            }

            if ($validator->shouldBlockByStability($package)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $package->getReleaseDate(), 'source' => 'packages'];
                $this->trackReportEntry($report, $package, true, $package->getReleaseDate());
                continue;
            }

            $releaseDate = $this->resolveReleaseDate($package);

            if ($releaseDate === null) {
                $filteredPackages[] = $package;
                $this->trackReportEntry($report, $package, false, null);
                continue;
            }

            if ($validator->shouldBlockByAge($package, $releaseDate)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $releaseDate, 'source' => 'packages'];
                $this->trackReportEntry($report, $package, true, $releaseDate);
                continue;
            }

            $filteredPackages[] = $package;
            $this->trackReportEntry($report, $package, false, $releaseDate);
        }

        $filteredFixedPackages = [];
        foreach ($fixedPackages as $package) {
            if (!$package instanceof PackageInterface) {
                continue;
            }

            if ($this->isRootPackage($package, $rootPackageName)) {
                $filteredFixedPackages[] = $package;
                $this->trackReportEntry($report, $package, false, null);
                continue;
            }

            if ($this->isSelfPackage($package)) {
                $filteredFixedPackages[] = $package;
                $this->trackReportEntry($report, $package, false, null);
                continue;
            }

            if ($validator->shouldBlockByStability($package)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $package->getReleaseDate(), 'source' => 'fixed'];
                $this->trackReportEntry($report, $package, true, $package->getReleaseDate());
                continue;
            }

            $releaseDate = $this->resolveReleaseDate($package);

            if ($releaseDate === null || !$validator->shouldBlockByAge($package, $releaseDate)) {
                $filteredFixedPackages[] = $package;
                $this->trackReportEntry($report, $package, false, $releaseDate);
                continue;
            }

            $blocked[] = ['package' => $package, 'releaseDate' => $releaseDate, 'source' => 'fixed'];
            $this->trackReportEntry($report, $package, true, $releaseDate);
        }

        if ($blocked !== []) {
            $logger->reportBlocked($blocked);
        }

        $this->reportWriter->write(getcwd() ?: '.', $this->currentCommand, $report);

        $event->setPackages($filteredPackages);
        $event->setUnacceptableFixedPackages($filteredFixedPackages);
    }

    private function isRootPackage(PackageInterface $package, string $rootPackageName): bool
    {
        return $package->getName() === $rootPackageName;
    }

    private function isSelfPackage(PackageInterface $package): bool
    {
        return $package->getName() === self::PACKAGE_NAME;
    }

    private function shouldLogCommand(string $command): bool
    {
        return in_array($command, ['install', 'update', 'require'], true);
    }

    /**
     * @param array<string, array{packageName: string, blocked: array<int, array{version: string, releaseDate: DateTimeImmutable|null}>, allowed: array<string, true>}> $report
     */
    private function trackReportEntry(array &$report, PackageInterface $package, bool $blocked, ?DateTimeImmutable $releaseDate): void
    {
        $name = $package->getName();

        if (!isset($report[$name])) {
            $report[$name] = [
                'packageName' => $name,
                'blocked' => [],
                'allowed' => [],
            ];
        }

        if ($blocked) {
            $report[$name]['blocked'][] = [
                'version' => $package->getPrettyVersion(),
                'releaseDate' => $releaseDate,
            ];

            return;
        }

        $report[$name]['allowed'][$package->getPrettyVersion()] = true;
    }

    private function isPlatformPackage(string $packageName): bool
    {
        $packageName = strtolower($packageName);

        return $packageName === 'php'
            || $packageName === 'composer'
            || $packageName === 'composer-plugin-api'
            || $packageName === 'composer-runtime-api'
            || str_starts_with($packageName, 'ext-')
            || str_starts_with($packageName, 'lib-');
    }

    private function resolveReleaseDate(PackageInterface $package): ?DateTimeImmutable
    {
        if ($this->isPlatformPackage($package->getName())) {
            return null;
        }

        if ($this->config->isIgnored($package->getName())) {
            return null;
        }

        try {
            if ($this->config->verbose) {
                $this->io->writeError(sprintf(
                    '<info>[Composer Quarantine]</info> checking %s %s',
                    $package->getName(),
                    $package->getPrettyVersion()
                ), true, IOInterface::VERBOSE);
                $this->io->writeError(sprintf(
                    '<info>[Composer Quarantine]</info> fetching Packagist metadata for %s',
                    $package->getName()
                ), true, IOInterface::VERBOSE);
            }

            $releaseDates = $this->fetcher->getReleaseDates($package->getName(), $this->config->allowDev);
            $prettyVersion = $package->getPrettyVersion();
            $candidates = [
                $prettyVersion,
                ltrim($prettyVersion, 'v'),
                str_starts_with($prettyVersion, 'v') ? substr($prettyVersion, 1) : 'v' . $prettyVersion,
            ];

            foreach ($candidates as $candidate) {
                if (isset($releaseDates[$candidate])) {
                    return $releaseDates[$candidate];
                }
            }

            return null;
        } catch (Throwable $throwable) {
            if (!$this->config->failOpen) {
                throw new RuntimeException(sprintf('Composer Quarantine cannot fetch metadata for %s: %s', $package->getName(), $throwable->getMessage()), 0, $throwable);
            }

            if ($this->config->verbose) {
                (new QuarantineLogger($this->io, $this->config))->warnFailOpen($package->getName(), $throwable->getMessage());
            }

            return null;
        }
    }

    private function createFetcher(): PackagistMetadataFetcher
    {
        return new PackagistMetadataFetcher(
            new HttpDownloader($this->io, $this->composer->getConfig(), []),
            $this->cache,
            $this->rateLimiter,
            $this->io,
            $this->config->verbose
        );
    }

    private function createRateLimiter(): RateLimiter
    {
        return new RateLimiter(
            max(0, $this->config->packagistRequestIntervalMilliseconds),
            null,
            null,
            $this->io,
            $this->config->verbose
        );
    }
}
