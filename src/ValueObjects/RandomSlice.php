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
        // Позиции нумеруются с нуля: rand(0, $size) давал бы позицию за концом списка.
        $result = $size < 1 ? 0 : rand(0, $size - 1);
        $this->path[] = $result;

        return $result;
    }

    public function getPath(): array
    {
        return $this->path;
    }
}
