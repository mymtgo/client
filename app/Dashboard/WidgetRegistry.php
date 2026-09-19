<?php

namespace App\Dashboard;

class WidgetRegistry
{
    /** @var array<string, WidgetType> */
    private array $types = [];

    /** @param iterable<int, WidgetType> $types */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    /** @return array<string, WidgetType> */
    public function all(): array
    {
        return $this->types;
    }

    public function find(string $key): ?WidgetType
    {
        return $this->types[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }
}
