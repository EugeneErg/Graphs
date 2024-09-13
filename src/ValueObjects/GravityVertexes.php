<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class GravityVertexes implements GravityInterface
{
    /** @var int[] */
    public array $vertexes;

    public function __construct(int ...$vertexes)
    {
        $this->vertexes = $vertexes;
    }

    /** @return int[] */
    public function getItems(): array
    {
        return $this->vertexes;
    }
}
