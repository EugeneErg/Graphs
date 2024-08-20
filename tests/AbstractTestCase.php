<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\Services\CanvasService;
use EugeneErg\Graphs\Services\EdgeService;
use EugeneErg\Graphs\Services\GraphService;
use EugeneErg\Graphs\Services\IntersectionService;
use EugeneErg\Graphs\Services\TreeService;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\GraphInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;

abstract class AbstractTestCase extends TestCase
{

    protected function getCanvasService(): CanvasService
    {
        return new CanvasService();
    }

    protected function getGraphService(?CanvasService $canvasService = null): GraphService
    {
        return new GraphService($canvasService ?? $this->getCanvasService());
    }

    protected function getTreeService(
        ?CanvasService $canvasService = null,
        ?GraphService $graphService = null,
    ): TreeService {
        $canvasService ??= $this->getCanvasService();

        return new TreeService(
            $canvasService,
            $graphService ?? $this->getGraphService($canvasService),
        );
    }

    protected static function getSimpleTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/SimpleTriangle.php');
    }

    protected static function getLine(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/Line.php');
    }

    protected static function getThreeLines(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/ThreeConnectedLInes.php');
    }

    protected static function getDot(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/Dot.php');
    }

    protected static function getRectangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/SimpleRectangle.php');
    }

    protected static function getTriangleInTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/TriangleInTriangle.php');
    }

    protected static function getTriangleInTriangleInTriangle(int $shift = 0): array
    {
        return self::shiftVertexes($shift, require __DIR__ . '/Cases/Graphs/TriangleInTriangleInTriangle.php');
    }

    protected static function shiftVertexes(int $shift, array $connections): array
    {
        return $shift === 0
            ? $connections
            : self::setVertexes(array_map(fn (int $vertex) => $vertex + $shift, array_keys($connections)), $connections);
    }

    protected static function setVertexes(array $vertexes, array $connections): array
    {
        $result = [];
        $replayVertexes = array_combine(array_keys($connections), $vertexes);

        foreach ($connections as $vertexA => $connection) {
            foreach ($connection as $vertexB => $value) {
                $result[$replayVertexes[$vertexA]][$replayVertexes[$vertexB]] = $value;
            }
        }

        return $result;
    }

    protected static function merge(bool $randomize, array ...$allConnections): array
    {
        $vertexCount = 0;
        $shifts = [];
        $connectionByNewVertex = [];

        foreach ($allConnections as $pos => $connections) {
            $shifts[$pos] = $vertexCount;
            $countConnections = count($connections);

            for ($i = 0; $i < $countConnections; $i++) {
                $connectionByNewVertex[$vertexCount + $i] = $pos;
            }

            $vertexCount += $countConnections;
        }

        $vertexes = range(0, $vertexCount - 1);

        if ($randomize) {
            $vertexes = array_rand($vertexes, $vertexCount);
        }

        $result = [];

        foreach ($vertexes as $newVertexA) {
            $pos = $connectionByNewVertex[$newVertexA];
            $shift = $shifts[$pos];
            $oldVertexA = $newVertexA - $shift;
            $result[$newVertexA] = [];

            foreach ($allConnections[$pos][$oldVertexA] as $oldVertexB => $value) {
                $result[$newVertexA][$oldVertexB + $shift] = $value;
            }
        }

        return $result;
    }

    protected static function graphToMatrix(GraphInterface $graph): array
    {
        $sizes = [];
        $maxSize = 0;
        $row = [' '];

        $vertexes = $graph->getVertexes();
        sort($vertexes);

        foreach ($vertexes as $vertex) {
            $row[] = $vertex;
            $size = strlen((string) $vertex);
            $sizes[] = $size;
            $maxSize = max($size, $maxSize);
        }

        $stringRow = implode('|', $row);
        $result = [$stringRow];

        foreach ($vertexes as $vertexA) {
            $row = [str_pad((string) $vertexA, $maxSize)];

            foreach ($vertexes as $pos => $vertexB) {
                $row[] = str_pad($graph->hasConnection($vertexA, $vertexB) ? $graph->getValue($vertexA, $vertexB) : '', $sizes[$pos]);
            }

            $result[] = implode('|', $row);
        }

        return $result;
    }

    protected static function changeValue(array $list, callable $callback): array
    {
        array_walk($list, function (mixed &$value) use ($callback) {
            $value = $callback($value);
        });

        return $list;
    }

    protected function getIntersectService(?CanvasService $canvasService = null,): IntersectionService
    {
        return new IntersectionService($canvasService ?? $this->getCanvasService());
    }

    protected function getEdgeService(
        ?CanvasService $canvasService = null,
        ?IntersectionService $intersectionService = null,
        ?GraphService $graphService = null,
    ): EdgeService {
        $canvasService ??= $this->getCanvasService();

        return new EdgeService(
            $canvasService,
            $intersectionService ?? $this->getIntersectService($canvasService),
            $graphService ?? $this->getGraphService($canvasService),
        );
    }

    protected function runPrivateMethod(array $callback, mixed ...$parameters): mixed
    {
        try {
            $method = new ReflectionMethod(...$callback);
            $method->setAccessible(true);

            return $method->invoke($callback[0], ...$parameters);
        } catch (ReflectionException $exception) {
            throw new LogicException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param bool[][] $branch
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    protected function arrayToDirectionGraph(array $branch): DirectionGraph
    {
        return $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));
    }
}