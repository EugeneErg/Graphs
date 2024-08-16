<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

class ZeroSlice implements SliceInterface
{
    private int $pathSize = 0;

    public function __construct()
    {
    }

    public function getNextValue(int $size): int
    {
        $this->pathSize++;

        return 0;
    }

    public function getPath(): array
    {
        return array_fill(0, $this->pathSize, 0);
    }
}