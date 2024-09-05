<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;

readonly class CanvasService
{
    /**
     * @return int[]
     */
    public function fill(Canvas $canvas, int $vertex, int $color): array
    {
        $oldColor = $canvas->getPixel($vertex);
        $canvas->setPixel($vertex, $color);
        $result = [$vertex];

        for (
            $vertex = reset($result);
            $vertex !== false;
            $vertex = next($result)
        ) {
            foreach ($canvas->graph->getConnection($vertex) ?? [] as $connectionVertex => $value) {
                if ($canvas->isPixel($connectionVertex, $oldColor)) {
                    $canvas->setPixel($connectionVertex, $color);
                    $result[] = $connectionVertex;
                }
            }
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     */
    public function setPixels(Canvas $canvas, array $vertexes, int $color): void
    {
        foreach ($vertexes as $vertex) {
            $canvas->setPixel($vertex, $color);
        }
    }
}
