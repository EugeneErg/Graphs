<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Edge
{
    use FindVertexTrait;

    /**
     * @param int[] $vertexes
     */
    public function __construct(public array $vertexes)
    {
    }

    public function getNormalVertexNumber(int $offset): int
    {
        $count = count($this->vertexes);

        return ($offset < 0 && $offset !== -$count ? $count : 0) + ($offset % $count);
    }

    public function getVertex(int $offset): int
    {
        return $this->vertexes[$this->getNormalVertexNumber($offset)];
    }

    /**
     * @return int[]
     */
    public function getVertexes(int $offset = 0, ?int $count = null): array
    {
        $result = [];
        $count = $count ?? count($this->vertexes);

        if ($count < 0) {
            for ($i = 0; $i > $count; $i--) {
                $result[] = $this->getVertex($i + $offset);
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $result[] = $this->getVertex($i + $offset);
            }
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     */
    public function replace(array $vertexes, int $start, int $length): self
    {
        return new self(
            array_merge($this->getVertexes($length + $start, count($this->vertexes) - $length), $vertexes),
        );
    }
}
