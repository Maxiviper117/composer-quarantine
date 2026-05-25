<?php

declare(strict_types=1);

use MaxiViper117\ComposerQuarantine\RateLimiter;

it('waits between consecutive requests', function (): void {
    $clock = 0;
    $sleeps = [];

    $limiter = new RateLimiter(
        1000,
        function (int $microseconds) use (&$clock, &$sleeps): void {
            $sleeps[] = $microseconds;
            $clock += $microseconds;
        },
        fn (): int => $clock
    );

    $limiter->acquire('pkg one');
    $limiter->acquire('pkg two');

    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBe(1_000_000);
});
