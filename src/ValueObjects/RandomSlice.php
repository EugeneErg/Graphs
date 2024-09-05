<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

class RandomSlice implements SliceInterface
{
    /** @var int[] */
    private array $path = [];

    public function __construct()
    {
    }

    public function getNextValue(int $size): int
    {
        $result = rand(0, $size);
        $this->path[] = $result;

        return $result;
    }

    public function getPath(): array
    {
        return $this->path;
    }
}
