<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

interface SliceInterface
{
    public function getNextValue(int $size): int;

    /** @return int[] */
    public function getPath(): array;
}