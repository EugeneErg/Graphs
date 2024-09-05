<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Replacement
{
    public int $firstVertex;

    public int $lastVertex;

    /**
     * @param int[] $vertexes
     */
    public function __construct(public array $vertexes, public int $start, public int $length)
    {
        $firstKey = array_key_first($this->vertexes);
        $this->firstVertex = $this->vertexes[$firstKey];
        $lastKey = array_key_last($this->vertexes);
        $this->lastVertex = $this->vertexes[$lastKey];
    }
}
