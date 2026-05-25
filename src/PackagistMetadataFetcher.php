<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\IO\IOInterface;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class PackagistMetadataFetcher
{
    private const PACKAGIST_BASE_URL = 'https://repo.packagist.org/p2';

    public function __construct(
        private readonly HttpDownloader $httpDownloader,
        private readonly CacheStore $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly IOInterface $io,
        private readonly bool $verbose,
    ) {
    }

    /**
     * @return array<string, DateTimeImmutable>
     */
    public function getReleaseDates(string $packageName, bool $includeDev): array
    {
        $cacheKey = strtolower($packageName) . ':' . ($includeDev ? 'dev' : 'stable');

        if ($this->cache->has($cacheKey)) {
            if ($this->verbose) {
                $this->io->writeError(sprintf(
                    '<info>[Composer Quarantine]</info> cache hit for %s',
                    $packageName
                ), true, IOInterface::VERBOSE);
            }

            return $this->cache->get($cacheKey);
        }

        $url = self::PACKAGIST_BASE_URL . '/' . str_replace('%2F', '/', rawurlencode($packageName));
        if ($includeDev) {
            $url .= '~dev';
        }
        $url .= '.json';

        try {
            $response = $this->fetchWithRateLimit($url, $packageName);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                $this->cache->set($cacheKey, []);

                return [];
            }

            $payload = $response->decodeJson();
            $versions = $this->extractVersions($payload, $packageName);
            $releaseDates = [];

            foreach ($versions as $version) {
                if (!isset($version['version'], $version['time'])) {
                    continue;
                }

                try {
                    $releaseDates[(string) $version['version']] = new DateTimeImmutable((string) $version['time']);
                } catch (Throwable) {
                    continue;
                }
            }

            $this->cache->set($cacheKey, $releaseDates);

            if ($this->verbose) {
                $this->io->writeError(sprintf('<info>[Composer Quarantine]</info> Packagist metadata loaded for %s (%d versions)', $packageName, count($releaseDates)), true, IOInterface::VERBOSE);
            }

            return $releaseDates;
        } catch (Throwable $throwable) {
            $this->cache->set($cacheKey, null);

            throw $throwable;
        }
    }

    private function fetchWithRateLimit(string $url, string $packageName): Response
    {
        $attempts = 3;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->rateLimiter->acquire($packageName);
            $response = $this->httpDownloader->get($url);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 429 && $statusCode !== 503) {
                if ($statusCode < 200 || ($statusCode >= 300 && $statusCode !== 404)) {
                    throw new RuntimeException(sprintf('Unexpected Packagist status %d for %s', $statusCode, $packageName));
                }

                return $response;
            }

            $this->rateLimiter->backoffFromResponse($response, $packageName);

            if ($attempt === $attempts) {
                throw new RuntimeException(sprintf('Packagist rate limit prevented fetching metadata for %s', $packageName));
            }
        }

        throw new RuntimeException(sprintf('Packagist rate limit prevented fetching metadata for %s', $packageName));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractVersions(array $payload, string $packageName): array
    {
        $packages = $payload['packages'][$packageName] ?? null;

        if (!is_array($packages)) {
            return [];
        }

        if (($payload['minified'] ?? null) === 'composer/2.0') {
            /** @var array<int, array<string, mixed>> $packages */
            $packages = MetadataMinifier::expand($packages);
        }

        return array_values(array_filter($packages, static fn (mixed $version): bool => is_array($version)));
    }
}
