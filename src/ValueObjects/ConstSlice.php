<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

class ConstSlice implements SliceInterface
{
    private int $pathSize = 0;

    /** @param int[] $path */
    public function __construct(private readonly array $path)
    {
    }

    public function getNextValue(int $size): int
    {
        $result = $this->path[$this->pathSize];
        $this->pathSize++;

        return $result;
    }

    public function getPath(): array
    {
        return $this->path;
    }
}