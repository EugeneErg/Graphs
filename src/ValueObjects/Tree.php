<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Tree
{
    /**
     * @param Graph $graph
     * @param DirectionGraph[]|null $branches
     * @param int[][]|null $connections
     */
    public function __construct(
        public Graph $graph,
        public ?array $branches = null,
        public ?array $connections = null,
    ) {
    }
}