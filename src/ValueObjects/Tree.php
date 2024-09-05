<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Tree
{
    /**
     * @param DirectionGraph[] $branches
     */
    public function __construct(
        public Graph $graph,
        public array $branches,
        public DirectionGraph $connections,
    ) {
    }
}
