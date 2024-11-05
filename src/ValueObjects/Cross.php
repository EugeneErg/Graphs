<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Cross
{
    public function __construct(
        public ?CrossPoint $first = null,
        public ?CrossPoint $last = null,
        public ?CrossPoint $cross = null,
    ) {
    }
}