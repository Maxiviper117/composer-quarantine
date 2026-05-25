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
    private Composer $composer;
    private IOInterface $io;
    private QuarantineConfig $config;
    private CacheStore $cache;
    private RateLimiter $rateLimiter;
    private PackagistMetadataFetcher $fetcher;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->cache = new CacheStore();
        $this->config = (new ConfigResolver())->resolve($composer, $io);
        $this->rateLimiter = $this->createRateLimiter();
        $this->fetcher = $this->createFetcher();
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

        $resolver = new ConfigResolver();
        $this->config = $resolver->resolve($this->composer, $this->io, $event);
        $this->rateLimiter = $this->createRateLimiter();
        $this->fetcher = $this->createFetcher();
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        if ($this->config->bypass) {
            return;
        }

        $packages = $event->getPackages();
        $fixedPackages = $event->getUnacceptableFixedPackages();
        $validator = new PackageAgeValidator($this->config);
        $logger = new QuarantineLogger($this->io, $this->config);
        $filteredPackages = [];
        $blocked = [];

        foreach ($packages as $package) {
            if (!$package instanceof PackageInterface) {
                continue;
            }

            if ($validator->shouldBlockByStability($package)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $package->getReleaseDate()];
                continue;
            }

            $releaseDate = $this->resolveReleaseDate($package);

            if ($releaseDate === null) {
                $filteredPackages[] = $package;
                continue;
            }

            if ($validator->shouldBlockByAge($package, $releaseDate)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $releaseDate];
                continue;
            }

            $filteredPackages[] = $package;
        }

        $filteredFixedPackages = [];
        foreach ($fixedPackages as $package) {
            if (!$package instanceof PackageInterface) {
                continue;
            }

            if ($validator->shouldBlockByStability($package)) {
                $blocked[] = ['package' => $package, 'releaseDate' => $package->getReleaseDate()];
                continue;
            }

            $releaseDate = $this->resolveReleaseDate($package);

            if ($releaseDate === null || !$validator->shouldBlockByAge($package, $releaseDate)) {
                $filteredFixedPackages[] = $package;
                continue;
            }

            $blocked[] = ['package' => $package, 'releaseDate' => $releaseDate];
        }

        if ($blocked !== []) {
            $logger->reportBlocked($blocked);
        }

        $event->setPackages($filteredPackages);
        $event->setUnacceptableFixedPackages($filteredFixedPackages);
    }

    private function resolveReleaseDate(PackageInterface $package): ?DateTimeImmutable
    {
        if ($this->config->isIgnored($package->getName())) {
            return null;
        }

        try {
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
