<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Closure;

final class RateLimiter
{
    private int $nextAvailableAtUs = 0;

    public function __construct(
        private readonly int $minimumIntervalMilliseconds = 1000,
        private readonly ?Closure $sleep = null,
        private readonly ?Closure $now = null,
        private readonly ?IOInterface $io = null,
        private readonly bool $verbose = false,
    ) {
    }

    public function acquire(string $context = 'Packagist request'): void
    {
        $nowUs = $this->nowUs();
        $delayUs = max(0, $this->nextAvailableAtUs - $nowUs);

        if ($delayUs > 0) {
            $this->log(sprintf('Rate limiter sleeping %dms before %s', (int) ceil($delayUs / 1000), $context));
            $this->sleepUs($delayUs);
            $nowUs = $this->nowUs();
        }

        $this->nextAvailableAtUs = max($nowUs, $this->nextAvailableAtUs) + ($this->minimumIntervalMilliseconds * 1000);
    }

    public function backoffFromResponse(Response $response, string $context = 'Packagist request'): void
    {
        $retryAfterSeconds = $this->parseRetryAfterSeconds($response);

        if ($retryAfterSeconds === null) {
            $retryAfterSeconds = max(1, (int) ceil($this->minimumIntervalMilliseconds / 1000)) * 2;
        }

        $this->nextAvailableAtUs = max($this->nextAvailableAtUs, $this->nowUs() + ($retryAfterSeconds * 1_000_000));
        $this->log(sprintf('Rate limiter backing off for %ds after %s', $retryAfterSeconds, $context));
    }

    private function nowUs(): int
    {
        if ($this->now !== null) {
            return (int) ($this->now)();
        }

        return (int) floor(microtime(true) * 1_000_000);
    }

    private function sleepUs(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->sleep !== null) {
            ($this->sleep)($microseconds);
            return;
        }

        usleep($microseconds);
    }

    private function parseRetryAfterSeconds(Response $response): ?int
    {
        $header = $response->getHeader('Retry-After');
        if ($header === null || $header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 ? $seconds : 1;
    }

    private function log(string $message): void
    {
        if (!$this->verbose || $this->io === null) {
            return;
        }

        $this->io->writeError(sprintf('<info>[Composer Quarantine]</info> %s', $message), true, IOInterface::VERBOSE);
    }
}
