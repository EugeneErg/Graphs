<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Intersection;
use EugeneErg\Graphs\ValueObjects\SliceInterface;
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
        SliceInterface $slice,
        ?array $outerEdge = null,
        int $level = 0,
    ): Edge {
        if (count($branch->getVertexes()) < 4) {
            return new Edge($branch->getVertexes());
        }

        $hasOuter = $outerEdge !== null;
        $outerEdge = $outerEdge ?? [];
        $edgeVertexesKey = $this->getKey($slice, $branch->getVertexes());
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
                            $resultChildren[] = $this->splitOnTreeEdges($graph, $slice, $path, $level + 1);
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

    private function getKey(SliceInterface $slice, array $values): string|int
    {
        $position = $slice->getNextValue(count($values));
        $keyValue = array_slice($values, $position, 1, true);

        return array_key_first($keyValue);
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
                        $canvas[$nextVertex] === 0
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

        if ($canvas[$vertexA] === 0) {
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
        SliceInterface $slice,
    ): array {
        $innerIntersections = $this->getInnerIntersections($branch, $path, $outerVertexes, $slice);
        $innerVertexes = array_map(fn (Intersection $intersection) => $intersection->vertexes, $innerIntersections);

        return array_merge(...$innerVertexes);
    }

    /**
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return Intersection[]
     * @throws Exception
     */
    private function getInnerIntersections(
        DirectionGraph $branch,
        array $path,
        array $outerVertexes,
        SliceInterface $slice,
    ): array {
        $intersections = $this->intersectionService->getIntersections($branch, $path, $outerVertexes);
        $matrix = $this->getIntersectionMatrix($path, $intersections);
        $knowns = [];
        $unknowns = [];
        $result = [];

        foreach ($intersections as $number => $intersection) {
            $intersection->isOuter
                ? $knowns[$number] = true
                : $unknowns[$number] = $intersection;
        }

        while ($unknowns !== [] || $knowns !== []) {
            $newKnowns = [];

            foreach ($knowns as $vertexA => $isOuter) {
                foreach ($matrix->getConnection($vertexA) ?? [] as $vertexB => $value) {
                    if (isset($unknowns[$vertexB])) {
                        $unknowns[$vertexB]->setIsOuter(!$isOuter);
                        $newKnowns[$vertexB] = !$isOuter;

                        if ($isOuter) {
                            $result[] = $unknowns[$vertexB];
                        }

                        unset($unknowns[$vertexB]);
                    } elseif ($intersections[$vertexB]->isOuter === $isOuter) {
                        throw new LogicException('graph is not planar');
                    }
                }
            }

            $knowns = $newKnowns;

            if ($newKnowns === [] && $unknowns !== []) {
                $vertexB = $this->getKey($slice, $unknowns);
                $result[] = $unknowns[$vertexB];
                $knowns[$vertexB] = false;
                $unknowns[$vertexB]->setIsOuter(false);
                unset($unknowns[$vertexB]);
            }
        }

        return $result;
    }

    /**
     * @param int[] $path
     * @param Intersection[] $intersections
     */
    private function getIntersectionMatrix(array $path, array $intersections): DirectionGraph
    {
        $matrix = new DirectionGraph([], array_keys($intersections));
        $intersectionsCount = count($intersections);

        foreach ($intersections as $number => $intersectionA) {
            for ($i = $number + 1; $i < $intersectionsCount; $i++) {
                $intersectionB = $intersections[$i];

                if ($this->intersectionService->isConflicted($intersectionA, $intersectionB, $path)) {
                    $matrix->setValue($number, $i, 1);
                }
            }
        }

        return $matrix;
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