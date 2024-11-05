<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Line2D
{
    public function __construct(
        public Point2D $pointA,
        public Point2D $pointB,
    ) {
    }
}