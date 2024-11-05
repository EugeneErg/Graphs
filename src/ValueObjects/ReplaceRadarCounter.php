<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class ReplaceRadarCounter
{
    /**
     * @param Radar[] $radars
     */
    public function __construct(
        public int $offset = 0,
        public int $length = 0,
        public array $radars = [],
    ) {
    }
}