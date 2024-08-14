<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Aggregates;
use EugeneErg\Graphs\ValueObjects\Graph;

class ArticulationVertexesAggregate
{
    private int $children;

    /** @var int[] */
    public array $articulationVertexes;

    /** @var int[] */
    private array $number;

    /** @var int[] */
    private array $index;

    public function __construct(public readonly Graph $graph)
    {
        $this->children = 0;
        $this->articulationVertexes = [];
        $this->number = [];
        $this->index = [];
        $this->refresh();
    }

    private function refresh(): void
    {
        $this->dfs($this->graph->vertexes[0]);

        if ($this->children > 1) {
            $this->articulationVertexes[] = $this->graph->vertexes[0];
        }
    }

    private function dfs(int $vertexA, int $parentVertex = null): void
    {
        $this->number[$vertexA]
            = $this->index[$vertexA]
            = $parentVertex === null ? 0 : $this->number[$parentVertex] + 1;

        foreach ($this->graph->connections[$vertexA] ?? [] as $vertexB => $value) {
            if ($vertexB === $parentVertex) {
                continue;
            }

            if (isset($this->number[$vertexB])) {
                $this->index[$vertexA] = min($this->index[$vertexA], $this->number[$vertexB]);
            } else {
                $this->dfs($vertexB, $vertexA);
                $this->index[$vertexA] = min($this->index[$vertexA], $this->index[$vertexB]);

                if ($parentVertex === null) {
                    $this->children++;
                } elseif ($this->number[$vertexA] <= $this->index[$vertexB]) {
                    $this->articulationVertexes[] = $vertexA;
                }
            }
        }
    }
}
