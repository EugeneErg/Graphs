<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * @property int[] $vertexes
 */
trait FindVertexTrait
{
    public function getVertexPosition(int $vertex): int
    {
        $result = $this->findVertexPosition($vertex);

        if (! is_int($result)) {
            throw new \RuntimeException('Vertex not found.');
        }

        return $result;
    }

    public function findVertexPosition(int $vertex): ?int
    {
        /** @var int|false $result */
        $result = array_search($vertex, $this->vertexes, true);

        return $result === false ? null : $result;
    }
}
