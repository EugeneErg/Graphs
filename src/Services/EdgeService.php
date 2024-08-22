<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Intersection;
use Exception;
use LogicException;

readonly class EdgeService
{
    public function __construct(
        private CanvasService $canvasService,
        private IntersectionService $intersectionService,
        private GraphService $graphService,
    ) {
    }

    /**
     * @param int[]|null $outerEdge
     * @throws Exception
     */
    public function splitOnTreeEdges(
        DirectionGraph $branch,
        SliceAggregate $slice,
        ?array $outerEdge = null,
    ): Edge {
        if (count($branch->getVertexes()) < 4) {
            return new Edge($branch->getVertexes());
        }

        $hasOuter = $outerEdge !== null;
        $outerEdge = $outerEdge ?? [];
        $edgeVertexesKey = $slice->getKey($branch->getVertexes());
        $edgeVertexes = $hasOuter ? array_flip($outerEdge) : [$branch->getVertex($edgeVertexesKey) => 0];
        $outerVertexes = array_fill_keys($hasOuter ? $outerEdge : [$branch->getVertex($edgeVertexesKey)], true);
        $resultChildren = [];
        $first = !$hasOuter;
        $needOuter = false;
        $finish = false;

        do {
            foreach ($edgeVertexes as $vertexA => $v) {
                unset($edgeVertexes[$vertexA]);

                foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                    if (($value !== 1 || $needOuter) && ($value !== 2 || !$needOuter)) {
                        continue;
                    }

                    if ($needOuter) {
                        $finish = true;
                    }

                    $path = $this->findShortEdge($branch, $vertexA, $vertexB, $first || $finish);

                    if ($path === null) {
                        throw new LogicException('Graph is not planar');
                    }

                    foreach ($path as $pos => $vertex) {
                        if (
                            $branch->hasValue($vertexA, $vertex)
                            && $branch->getValue($vertexA, $vertex) === 1
                            && $pos > 1
                        ) {
                            array_splice($path, $pos + 1);

                            break;
                        }
                    }

                    $innerVertexes = $this->getInnerVertexes($branch, $path, $outerVertexes, $slice);

                    if (
                        $first && !$hasOuter
                        && count($innerVertexes) + count($path) === count($branch->getVertexes())
                    ) {
                        $innerVertexes = [];
                    }

                    $first = false;
                    $flipPath = array_flip($path);

                    if (!$needOuter || $hasOuter) {
                        if ($innerVertexes === []) {
                            $newEdge = new Edge($path);
                            $resultChildren[] = $newEdge;
                        } else {
                            /** @var DirectionGraph $graph */
                            $graph = $this->graphService->createSubGraph($branch, array_merge($path, $innerVertexes));
                            $graph->replaceConnection($this->pathToConnections($path));
                            $resultChildren[] = $this->splitOnTreeEdges($graph, $slice, $path);
                        }
                    } elseif ($outerEdge === []) {
                        $outerEdge = $path;
                        $hasOuter = true;
                    } else {
                        throw new LogicException('Is not planar graph');
                    }

                    foreach ($path as $vertex) {
                        $outerVertexes[$vertex] = true;
                    }

                    $branch->replaceConnection($this->pathToOuterConnection($path, $branch));
                    $branch->replaceConnection($this->disconnectVertexes($innerVertexes, $branch));
                    $edgeVertexes = array_replace($edgeVertexes, $flipPath);

                    continue 3;
                }
            }

            $edgeVertexes = array_flip($branch->vertexes);
            $needOuter = true;
        } while (!$finish);

        if ($outerEdge === []) {
            throw new LogicException('Is not planar graph');
        }

        return new Edge($outerEdge, $resultChildren);
    }

    /**
     * @return int[]|null
     */
    private function findShortEdge(DirectionGraph $graph, int $vertexA, int $vertexB, bool $first = false): ?array
    {
        if ($first) {
            $graph->unsetValue($vertexB, $vertexA, true);
        } else {
            foreach ($graph->getConnection($vertexA) ?? [] as $vertex => $value) {
                if ($value === 1) {
                    $graph->unsetValue($vertex, $vertexA, true);
                }
            }
        }

        $result = $this->findShortPath($graph, $vertexA, $vertexB);

        foreach ($graph->getConnection($vertexA) ?? [] as $vertex => $value) {
            $graph->setValue($vertex, $vertexA, $value, true);
        }

        return $result;
    }

    /**
     * @return int[]|null
     */
    private function findShortPath(DirectionGraph $graph, int $vertexA, int $vertexB): ?array
    {
        $steps = [[$vertexB => null]];
        $values = [];
        $canvas = new Canvas($graph);

        for ($step = 0; $step < count($steps); $step++) {
            foreach ($steps[$step] as $currentVertex => $prevVertex) {
                $currentValue = !empty($values[$currentVertex]);
                unset($values[$currentVertex]);

                if ($graph->hasConnection($currentVertex, $vertexA)) {
                    $this->canvasService->setPixels($canvas, [$vertexA], 1);
                    $steps[$step + 1][$vertexA] = $currentVertex;

                    break(2);
                }

                foreach ($graph->getConnection($currentVertex) ?? [] as $nextVertex => $value) {
                    if (
                        $canvas->isPixel($nextVertex, 0)
                        && (
                            (!$currentValue && $value !== 3)
                            || ($currentValue && $value === 2)
                        )
                    ) {
                        $this->canvasService->setPixels($canvas, [$vertexA], 1);
                        $steps[$step + 1][$nextVertex] = $currentVertex;

                        if ($currentValue) {
                            $values[$currentVertex] = true;
                        }
                    }
                }
            }
        }

        if ($canvas->isPixel($vertexA, 0)) {
            return null;
        }

        $currentVertex = $vertexA;
        $result = [$currentVertex];

        for ($step = count($steps) - 1; $step > 0; $step--) {
            $currentVertex = $steps[$step][$currentVertex];
            $result[] = $currentVertex;
        }

        return $result;
    }

    /**
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return int[]
     * @throws Exception
     */
    private function getInnerVertexes(
        DirectionGraph $branch,
        array $path,
        array $outerVertexes,
        SliceAggregate $slice,
    ): array {
        $innerIntersections = $this->intersectionService->getInnerIntersections($branch, $path, $outerVertexes, $slice);
        $innerVertexes = array_map(fn (Intersection $intersection) => $intersection->vertexes, $innerIntersections);

        return array_merge(...$innerVertexes);
    }

    /**
     * @param int[] $path
     * @return int[][]
     */
    private function pathToConnections(array $path, int $value = 2): array
    {
        $lastKey = array_key_last($path);
        $vertexB = $path[$lastKey];
        $result = [];

        foreach ($path as $vertexA) {
            $result[$vertexA][$vertexB] = $value;
            $vertexB = $vertexA;
        }

        return $result;
    }

    /**
     * @param int[] $path
     * @return array<int|null>[]
     */
    private function pathToOuterConnection(array $path, DirectionGraph $branch): array
    {
        $lastKey = array_key_last($path);
        $vertexB = $path[$lastKey];
        $result = [];

        foreach ($path as $vertexA) {
            $value = $branch->getValue($vertexA, $vertexB);
            $result[$vertexA][$vertexB] = $value === 2 ? null : $value + 1;
            $vertexB = $vertexA;
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     * @return null[][]
     */
    private function disconnectVertexes(array $vertexes, DirectionGraph $branch): array
    {
        $result = [];

        foreach ($vertexes as $vertexA) {
            foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                $result[$vertexA][$vertexB] = null;
            }
        }

        return $result;
    }
}