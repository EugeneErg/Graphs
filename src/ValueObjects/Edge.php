<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Edge
{
    /**
     * @param int[] $vertexes
     * @param Edge[] $children
     */
    public function __construct(public array $vertexes, public array $children = [])
    {
    }
}