<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Graph
{
    /**
     * @param bool[][] $connections
     * @param int[] $vertexes
     */
    public function __construct(public array $connections, public array $vertexes)
    {
    }
}