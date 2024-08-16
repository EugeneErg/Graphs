<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\ValueObjects\Tree;

readonly class TreeService
{
    public function __construct(
        private CanvasService $canvasService,
        private GraphService $graphService,
    ) {
    }

    public function fromConnectionGraph(ArticulationVertexesAggregate $articulationVertexesAggregate): Tree
    {
        $directionGraph = $this->graphService->graphToDirection($articulationVertexesAggregate->graph);

        if ($articulationVertexesAggregate->articulationVertexes === []) {
            return new Tree(
                $articulationVertexesAggregate->graph,
                [$directionGraph],
            );
        }

        $articulationVertexes = $articulationVertexesAggregate->articulationVertexes;
        $result = [];
        $this->split($articulationVertexes, new Canvas($articulationVertexesAggregate->graph), $result);
        $branches = array_map(
            fn (array $vertexes) => $this->graphService->createSubGraph($directionGraph, $vertexes),
            $result,
        );
        $connections = [];

        foreach ($result as $branchNumber => $vertexes) {
            foreach ($vertexes as $vertex) {
                $connections[$vertex][] = $branchNumber;
            }
        }

        return new Tree($articulationVertexesAggregate->graph, $branches, $connections);
    }

    private function split(
        array &$articulationVertex,
        Canvas $canvas,
        array &$result,
        int $maxColor = 0
    ): bool {
        $color = $maxColor;
        $hasResult = false;

        foreach ($articulationVertex as $pos => $vertexA) {
            if (!$canvas->isPixel($vertexA, $maxColor)) {
                continue;
            }

            unset($articulationVertex[$pos]);

            foreach($canvas->graph->connections[$vertexA] ?? [] as $vertexB => $value) {
                if (!$canvas->isPixel($vertexB, $maxColor)) {
                    continue;
                }

                $hasResult = true;
                $this->canvasService->setPixels($canvas, [$vertexA], ++$color);
                $vertexes = $this->canvasService->fill($canvas, $vertexB, $color);
                $vertexes[$vertexA] = $vertexA;

                if (!$this->split($articulationVertex, $canvas, $result, $color)) {
                    $result[] = $vertexes;
                }
            }
        }

        return $hasResult;
    }
}