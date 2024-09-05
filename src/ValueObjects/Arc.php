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
}
