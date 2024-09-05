<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Intersection;

readonly class IntersectionService
{
    public function __construct(private CanvasService $canvasService)
    {
    }

    /**
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return Intersection[]
     * @throws \Exception
     */
    public function getInnerIntersections(
        DirectionGraph $branch,
        array $path,
        array $outerVertexes,
        SliceAggregate $slice,
    ): array {
        $intersections = $this->getIntersections($branch, $path, $outerVertexes);
        $matrix = $this->getIntersectionMatrix($path, $intersections);
        $defined = [];
        $undefined = [];
        $result = [];

        foreach ($intersections as $number => $intersection) {
            isset($intersection->isOuter)
                ? $defined[$number] = true
                : $undefined[$number] = $intersection;
        }

        while ($undefined !== [] || $defined !== []) {
            $newDefined = [];

            foreach ($defined as $vertexA => $isOuter) {
                foreach ($matrix->getConnection($vertexA) ?? [] as $vertexB => $value) {
                    if (isset($undefined[$vertexB])) {
                        $undefined[$vertexB]->setIsOuter(! $isOuter);
                        $newDefined[$vertexB] = ! $isOuter;

                        if ($isOuter) {
                            $result[] = $undefined[$vertexB];
                        }

                        unset($undefined[$vertexB]);
                    } elseif ($intersections[$vertexB]->isOuter === $isOuter) {
                        throw new \LogicException('graph is not planar');
                    }
                }
            }

            $defined = $newDefined;

            if ($newDefined === [] && $undefined !== []) {
                $vertexB = $slice->getKey($undefined);
                $result[] = $undefined[$vertexB];
                $defined[$vertexB] = false;
                $undefined[$vertexB]->setIsOuter(false);
                unset($undefined[$vertexB]);
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

                if ($this->isConflicted($intersectionA->connections, $intersectionB->connections, $path)) {
                    $matrix->setValue($number, $i, 1);
                }
            }
        }

        return $matrix;
    }

    /**
     * @param DirectionGraph $branch
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return Intersection[]
     */
    private function getIntersections(DirectionGraph $branch, array $path, array $outerVertexes): array
    {
        $canvas = new Canvas($branch);
        $this->canvasService->setPixels($canvas, $path, 1);
        $color = 1;
        $colors = [];
        $intersections = [];

        foreach ($path as $vertexA) {
            foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                $oldColor = $canvas->getPixel($vertexB);

                if ($oldColor !== 1) {
                    $intersections[$oldColor === 0 ? $color + 1 : $oldColor][$vertexA] = true;
                }

                if ($oldColor !== 0) {
                    continue;
                }

                $color++;
                $colors[$color] = $this->canvasService->fill($canvas, $vertexB, $color);
            }
        }

        $outerColors = [];

        foreach ($outerVertexes as $outerVertex => $v) {
            $outerColors[$canvas->getPixel($outerVertex)] = true;
        }

        $result = [];

        foreach ($colors as $color => $vertexes) {
            $result[] = new Intersection(
                $vertexes,
                $intersections[$color],
                isset($outerColors[$color]) ?: null,
            );
        }

        return $result;
    }

    /**
     * @param array<int, bool> $connectionsA
     * @param array<int, bool> $connectionsB
     * @param int[] $path
     */
    private function isConflicted(array $connectionsA, array $connectionsB, array $path): bool
    {
        $can = 0;
        $step = 0;

        foreach ($path as $vertex) {
            $aIsConnected = $connectionsA[$vertex] ?? false;
            $bIsConnected = $connectionsB[$vertex] ?? false;

            if (! $aIsConnected && ! $bIsConnected) {
                continue;
            }

            if (! $can) {
                $can = 3 - (int) $aIsConnected - ($bIsConnected ? 2 : 0);
                $step += $can === 0;
            } elseif ($step === 1) {
                $step += ($can === 1 && $bIsConnected) || ($can === 2 && $aIsConnected);
            } else {
                $step += ($can === 1 && $aIsConnected) || ($can === 2 && $bIsConnected);
            }

            if ($step === 3) {
                return true;
            }
        }

        return false;
    }
}
