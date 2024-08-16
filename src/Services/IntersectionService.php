<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Intersection;

readonly class IntersectionService
{
    public function __construct(private CanvasService $canvasService)
    {
    }

    /**
     * @param DirectionGraph $branch
     * @param int[] $path
     * @param array<int, bool> $outerVertexes
     * @return Intersection[]
     */
    public function getIntersections(DirectionGraph $branch, array $path, array $outerVertexes): array
    {
        $canvas = new Canvas($branch);
        $this->canvasService->setPixels($canvas, $path, 1);
        $color = 1;
        $colors = [];
        $intersections = [];

        foreach ($path as $vertexA) {
            foreach ($branch->getConnection($vertexA) ?? [] as $vertexB => $value) {
                $oldColor = $canvas[$vertexB];

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
            $outerColors[$canvas[$outerVertex]] = true;
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
     * @param int[] $path
     */
    public function isConflicted(Intersection $intersectionA, Intersection $intersectionB, array $path): bool
    {
        $can = 0;
        $step = 0;

        foreach ($path as $vertex) {
            $aIsConnected = $intersectionA->connections[$vertex] ?? false;
            $bIsConnected = $intersectionB->connections[$vertex] ?? false;

            if (!$aIsConnected && !$bIsConnected) {
                continue;
            }

            if (!$can) {
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