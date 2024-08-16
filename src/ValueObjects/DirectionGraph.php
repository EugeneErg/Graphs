<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final class DirectionGraph implements GraphInterface
{
    /**
     * @param int[][] $connections
     * @param int[] $vertexes
     */
    public function __construct(private array $connections, public readonly array $vertexes)
    {
    }

    public function unsetValue(int $vertexA, int $vertexB, bool $direction = false): void
    {
        unset($this->connections[$vertexA][$vertexB]);

        if (!$direction) {
            unset($this->connections[$vertexB][$vertexA]);
        }
    }

    public function getVertexes(): array
    {
        return $this->vertexes;
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function hasConnection(int $vertexA, int $vertexB): bool
    {
        return isset($this->connections[$vertexA][$vertexB]);
    }

    public function getValue(int $vertexA, int $vertexB): int
    {
        return $this->connections[$vertexA][$vertexB];
    }

    public function getVertex(int $position): int
    {
        return $this->vertexes[$position];
    }

    public function getConnection(int $vertex): ?array
    {
        return $this->connections[$vertex] ?? null;
    }

    public function setValue(int $vertexA, int $vertexB, mixed $value, bool $direction = false): void
    {
        $this->connections[$vertexA][$vertexB] = $value;

        if (!$direction) {
            $this->connections[$vertexB][$vertexA] = $value;
        }
    }

    public function hasValue(int $vertexA, int $vertexB): bool
    {
        return isset($this->connections[$vertexA][$vertexB]);
    }

    /**
     * @param array<int|null>[] $connections
     */
    public function replaceConnection(array $connections, bool $direction = false): void
    {
        foreach ($connections as $vertexA => $connection) {
            foreach ($connection as $vertexB => $value) {
                $value === null
                    ? $this->unsetValue($vertexA, $vertexB, $direction)
                    : $this->setValue($vertexA, $vertexB, $value, $direction);
            }
        }
    }

    /**
     * @param int[] $path
     */
    public function setOuterEdge(array $path): void
    {
        $lastKey = array_key_last($path);
        $prev = $path[$lastKey];

        foreach ($path as $vertex) {
            $this->setValue($prev, $vertex, 2);
            $prev = $vertex;
        }
    }
}