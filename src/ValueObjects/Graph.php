<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Graph implements GraphInterface
{
    /**
     * @param bool[][] $connections
     * @param int[] $vertexes
     */
    public function __construct(private array $connections, public array $vertexes)
    {
    }

    public function getVertexes(): array
    {
        return $this->vertexes;
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnection(int $vertex): ?array
    {
        return $this->connections[$vertex] ?? null;
    }

    public function hasValue(int $vertexA, int $vertexB): bool
    {
        return isset($this->connections[$vertexA][$vertexB]);
    }

    public function hasConnection(int $vertexA): bool
    {
        return isset($this->connections[$vertexA]);
    }

    public function getValue(int $vertexA, int $vertexB): bool
    {
        return $this->connections[$vertexA][$vertexB];
    }

    public function getVertex(int $position): int
    {
        return $this->vertexes[$position];
    }
}
