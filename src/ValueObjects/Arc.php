<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Arc
{
    /**
     * @param int[][] $vertexes
     */
    public function __construct(public GravityInterface $gravity, public array $vertexes)
    {
    }

    public function firstVertex(): int
    {
        return $this->vertexes[0][0];
    }

    public function lastVertex(): int
    {
        $vertexes = $this->vertexes;
        /** @var int[] $lastVertexes */
        $lastVertexes = end($vertexes);
        /** @var int $lastVertex */
        $lastVertex = end($lastVertexes);

        return $lastVertex;
    }
}
