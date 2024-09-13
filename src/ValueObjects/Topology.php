<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Topology
{
    /**
     * @param Arc[] $arcs
     */
    public function __construct(
        public Edge $outerEdge,
        public array $arcs,
    ) {
    }
}