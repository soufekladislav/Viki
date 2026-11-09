<?php
declare(strict_types=1);
namespace App\Core;
use RuntimeException;
final class Container
{
    private array $bindings = [];
    private array $instances = [];
    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
    }
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) return $this->instances[$id];
        if (!array_key_exists($id, $this->bindings)) throw new RuntimeException("Service [$id] is not bound.");
        return $this->instances[$id] = $this->bindings[$id]($this);
    }
}
