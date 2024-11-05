<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Radar
{
    public bool $connect;

    public function __construct(
        public Point2D $coordinate,
        public Angle $angle,
        public float $distance,
        ?bool $connect = null,
    ) {
    }

    public function setConnect(bool $connect): void
    {
        $this->connect = $connect;
    }
}