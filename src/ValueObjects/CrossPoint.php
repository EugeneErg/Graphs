<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class CrossPoint
{
    public function __construct(public int $number, public bool $current, public bool $isEqual)
    {
    }
}