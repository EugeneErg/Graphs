<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Point2D
{
    public function __construct(
        public float $x = .0,
        public float $y = .0,
    ) {
    }
}