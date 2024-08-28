<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class TreeEdge
{
    /**
     * @param TreeEdge[] $children
     */
    public function __construct(
        public Edge $edge,
        public array $children = [],
    ) {
    }
}