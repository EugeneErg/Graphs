<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

interface GraphInterface
{
    /**
     * @param array $connections
     * @param int[] $vertexes
     */
    public function __construct(array $connections, array $vertexes);

    /** @return int[] */
    public function getVertexes(): array;

    public function getConnections(): array;

    public function hasConnection(int $vertexA, int $vertexB): bool;

    public function getValue(int $vertexA, int $vertexB): mixed;

    public function getVertex(int $position): int;
}