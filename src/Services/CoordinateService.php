<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Angle;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityInterface;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Topology;

final readonly class CoordinateService
{
    /**
     * @return Point2D[]
     */
    public function getCoordinates(
        Topology $topology,
        float $radius,
        ?Point2D $center = null,
    ): array {
        $result = $this->getCircle($topology->outerEdge->vertexes, $radius, $center);

        foreach ($topology->arcs as $arc) {
            $indexes = $this->getIndexes($arc);
            $begin = $result[$arc->firstVertex()];
            $end = $result[$arc->lastVertex()];
            $center = $this->getGravityCenter($arc->gravity, $result);

            foreach ($indexes as $vertex => $index) {
                if (!isset($coordinates[$vertex])) {
                    $coordinates[$vertex] = $this->bezierPoint($begin, $center, $end, $index);
                }
            }
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     *
     * @return Point2D[]
     */
    private function getCircle(array $vertexes, float $radius, ?Point2D $center = null, ?Angle $startAngle = null): array
    {
        $vertexCount = count($vertexes);

        if ($vertexCount === 0) {
            return [];
        }

        if ($vertexCount === 1) {
            return array_fill_keys($vertexes, $center);
        }

        $center ??= new Point2D();
        $startAngle ??= new Angle();
        $angle = Angle::pi(2)->divided($vertexCount);
        $result = [];
        $pos = 0;

        foreach ($vertexes as $vertex) {
            $result[$vertex] = $this->getPoint($radius, $startAngle->plus($angle->times($pos)), $center);
            $pos++;
        }

        return $result;
    }

    private function getPoint(float $distance, Angle $angle, Point2D $center): Point2D
    {
        return new Point2D($center->x + $distance * $angle->sin(), $center->y + $distance * -$angle->cos());
    }


    /** @return float[] */
    public function getIndexes(Arc $arc): array
    {
        $result = [];
        $count = count($arc->vertexes);

        foreach ($arc->vertexes as $num1 => $vertexes) {
            $step = 1 / (count($vertexes) * $count - 1);
            $prevCount = $num1 * count($vertexes);

            foreach ($vertexes as $num2 => $vertex) {
                $result[$vertex] = $step * ($num2 + $prevCount);
            }
        }

        return $result;
    }

    private function getGravityCenter(GravityInterface $gravity, array $coordinates): Point2D
    {
        $points = $gravity->getItems();
        $x = 0;
        $y = 0;

        /** @var int|GravityInterface $point */
        foreach ($points as $point) {
            $coordinate = $point instanceof GravityInterface
                ? $this->getGravityCenter($point, $coordinates)
                : $coordinates[$point];
            $x += $coordinate->x;
            $y += $coordinate->y;
        }

        return new Point2D(
            $x / count($points),
            $y / count($points)
        );
    }

    private function bezierPoint(Point2D $begin, Point2D $center, Point2D $end, float $index): Point2D
    {
        $index1 = pow(1 - $index, 2);
        $index2 = 2 * $index * (1 - $index);
        $index3 = pow($index, 2);

        return new Point2D(
            $index1 * $begin->x + $index2 * $center->x + $index3 * $end->x,
            $index1 * $begin->y + $index2 * $center->y + $index3 * $end->y,
        );
    }

    /**
     * @param Point2D[] $coordinates
     * @param Edge[] $edges
     * @return Point2D[]
     */
    public function relaxCoordinates(Edge $outerEdge, array $edges, array $coordinates): array
    {
//        $outerEdge = $this->getDoubleArea($outerEdge->vertexes, $coordinates) < 0
//            ? new Edge(array_reverse($outerEdge->vertexes))
//            : $outerEdge;
        $outerVertexes = array_flip($outerEdge->vertexes);

        foreach ($edges as $pos => $edge) {
            $edges[$pos] = $this->getDoubleArea($edge->vertexes, $coordinates) < 0
                ? new Edge(array_reverse($edge->vertexes))
                : $edge;
        }

        $rooms = [];

        foreach ($edges as $edge) {
            foreach ($edge->vertexes as $vertex) {
                if (!isset($outerVertexes[$vertex])) {
                    $rooms[$vertex][] = $edge->vertexes;
                }
            }
        }

        $flats = [];

        foreach ($rooms as $vertex => $room) {
            $flats[$vertex] = $this->mergeRooms($room, $vertex);
        }

        $accuracy = 0.001;

        do {
            $found = false;

            foreach ($flats as $vertex => $flat) {
                $visibleCoordinates = $this->getVisibleCoordinates($vertex, $flat, $coordinates);
                $oldCoordinate = $coordinates[$vertex];
                $coordinates[$vertex] = $this->computeCentroid($visibleCoordinates);
                $found = $found || $this->getDoubleDistance($oldCoordinate, $coordinates[$vertex]) >= $accuracy;
            }
        } while ($found);

        return $coordinates;
    }

    /**
     * @param Point2D[] $coordinates
     */
    private function getDoubleArea(array $vertexes, array $coordinates): float
    {
        $result = 0;
        $prevVertex = end($vertexes);
        $prevPoint = $coordinates[$prevVertex];

        foreach ($vertexes as $vertex) {
            $point = $coordinates[$vertex];
            $result += $this->getDoubleDistance($point, $prevPoint);
            $prevPoint = $point;
        }

        return $result;
    }

    /**
     * @param Edge[] $edges
     * @return int[]
     */
    private function mergeRooms(array $edges, int $vertex): array
    {
        $positions = [];
        $lines = [];

        foreach ($edges as $num => $edge) {
            /** @var int $posA */
            $posA = array_search($vertex, $edge->vertexes);
            $prevVertex = $edge->getNormalVertexNumber($posA - 1);
            $nextVertex = $edge->getNormalVertexNumber($posA + 1);
            $lines[$prevVertex] = $num;
            $positions[$num] = [$posA, $nextVertex];
        }

        $result = [];
        $edgeCount = count($edges);
        $currentPos = 0;

        for ($i = 0; $i < $edgeCount; $i++) {
            [$pos, $nextVertex] = $positions[$currentPos];
            $edge = $edges[$currentPos];
            $result[] = $edge->getVertexes($pos + 1, count($edge->vertexes) - 1);
            $currentPos = $lines[$nextVertex];
        }

        return array_merge(...$result);
    }

    /**
     * @param int[] $vertexes
     * @param Point2D[] $coordinates
     * @return Point2D[]
     */
    private function getVisibleCoordinates(int $vertex, array $vertexes, array $coordinates): array
    {
        $visibleVertices = [];
        $origin = $coordinates[$vertex];

        foreach ($vertexes as $vertexB) {
            $target = $coordinates[$vertexB];

            if ($this->isVisible($origin, $target, $coordinates, $vertexes)) {
                $visibleVertices[] = $target;
            }
        }

        return $visibleVertices;
    }

    private function isVisible(Point2D $origin, Point2D $target, array $coordinates, array $vertexes): bool
    {
        foreach ($vertexes as $i => $v1) {
            $v2 = $vertexes[($i + 1) % count($vertexes)];

            if ($this->segmentsIntersect($origin, $target, $coordinates[$v1], $coordinates[$v2])) {
                return false;
            }
        }

        return true;
    }

    private function segmentsIntersect(Point2D $a, Point2D $b, Point2D $c, Point2D $d): bool
    {
        $d1 = $this->direction($c, $d, $a);
        $d2 = $this->direction($c, $d, $b);
        $d3 = $this->direction($a, $b, $c);
        $d4 = $this->direction($a, $b, $d);

        return $d1 * $d2 < 0 && $d3 * $d4 < 0;
    }

    private function direction(Point2D $a, Point2D $b, Point2D $c): float
    {
        return ($b->x - $a->x) * ($c->y - $a->y) - ($b->y - $a->y) * ($c->x - $a->x);
    }

    /**
     * @param Point2D[] $polygon
     */
    private function computeCentroid(array $polygon): Point2D
    {
        $xSum = 0;
        $ySum = 0;
        $n = count($polygon);

        foreach ($polygon as $point) {
            $xSum += $point->x;
            $ySum += $point->y;
        }

        return new Point2D($xSum / $n, $ySum / $n);
    }

    private function getDoubleDistance(Point2D $a, Point2D $b): float
    {
        return ($a->x - $b->x) * ($a->y - $b->y);
    }
}