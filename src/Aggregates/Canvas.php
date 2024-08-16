<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\GraphInterface;

final class Canvas
{
    private array $pixels = [];

    public function __construct(public readonly GraphInterface $graph)
    {
    }

    public function getPixel(int $vertex): int
    {
        return $this->pixels[$vertex] ?? 0;
    }

    public function setPixel(int $vertex, int $color): void
    {
        $this->pixels[$vertex] = $color;
    }

    public function isPixel(int $vertex, int $color): bool
    {
        return $this->getPixel($vertex) === $color;
    }
}