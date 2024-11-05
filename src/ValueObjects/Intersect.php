<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Intersect
{
    /**
     * @param Angle[] $intersect
     */
    public function __construct(
        public IntersectType $type,
        public array $intersect = [],
    ) {
    }
}