<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

final class CacheStore
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }
}
