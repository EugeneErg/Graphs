<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Angle;
use EugeneErg\Graphs\ValueObjects\Arc;
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
}