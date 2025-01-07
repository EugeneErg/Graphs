<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Angle;
use EugeneErg\Graphs\ValueObjects\AngleIntersectType;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Cross;
use EugeneErg\Graphs\ValueObjects\CrossPoint;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityInterface;
use EugeneErg\Graphs\ValueObjects\Intersect;
use EugeneErg\Graphs\ValueObjects\IntersectType;
use EugeneErg\Graphs\ValueObjects\Line2D;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Radar;
use EugeneErg\Graphs\ValueObjects\ReplaceRadarCounter;
use EugeneErg\Graphs\ValueObjects\Topology;
use LogicException;
use RuntimeException;

final readonly class CoordinateService2
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
    public function relaxCoordinates(Edge $outerEdge, array $edges, array &$coordinates): array
    {
        $outerEdge = $this->getDoubleArea($outerEdge->vertexes, $coordinates) < 0
            ? new Edge(array_reverse($outerEdge->vertexes))
            : $outerEdge;

        foreach ($edges as $pos => $edge) {
            $edges[$pos] = $this->getDoubleArea($edge->vertexes, $coordinates) < 0
                ? new Edge(array_reverse($edge->vertexes))
                : $edge;
        }

        $rooms = [];

        foreach ($edges as $edge) {
            foreach ($edge->vertexes as $vertex) {
                $rooms[$vertex][] = $edge->vertexes;
            }
        }

        $flats = [];

        foreach ($rooms as $vertex => $room) {
            $flats[$vertex] = $this->mergeRooms($room, $vertex);
        }

        foreach ($flats as $vertex => $flat) {
            $visibleCoordinates = $this->getVisibleCoordinates($vertex, $flat, $coordinates);
        }


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
            $result += ($point->x - $prevPoint->x) * ($point->y - $prevPoint->y);
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
        $visorCoordinate = $coordinates[$vertex];
        $lastRadarNumber = $prevRadar = null;
        $visibleCounter = [];
        $visible = true;
        $zeroAngle = new Angle();
        $piAngle = Angle::pi();

        foreach ($vertexes as $vertex) {
            $currentCoordinate = $coordinates[$vertex];
            $currentRadar = new Radar(
                coordinate: $currentCoordinate,
                angle: $this->getAngle($visorCoordinate, $currentCoordinate),
                distance: $this->getDistance($visorCoordinate, $currentCoordinate),
            );

            if ($prevRadar === null) {
                $currentRadar->setConnect(false);
                $prevRadar = $currentRadar;

                continue;
            }

            $shiftAngle = $currentRadar->angle->minus($prevRadar->angle)->modulo();

            if ($shiftAngle->greaterThan($piAngle)) {
                $shiftAngle = $piAngle->minus($shiftAngle);
            }

            //todo каждый раз, когда идем вперед, проверяем, загораживаем ли видимую часть, и если да, перестраиваем её
            //todo каждый раз, когда идем назад, проверяем не пересекли ли мы видимую часть, и если да, обрезаем её

            if ($shiftAngle->greaterThan($zeroAngle)) {
                //тут может включиться видимость
                [
                    'visible' => $visible,
                    'lastRadarNumber' => $lastRadarNumber,
                    'replace' => $replace,
                ] = $this->tryAddPoint(
                    counter: $visibleCounter,
                    lastConnectedNumber: $lastRadarNumber,
                    visorCoordinate: $visorCoordinate,
                    prevRadar: $prevRadar,
                    currentRadar: $currentRadar,
                );
            } elseif ($visible) {
                //тут может выключиться видимость
                [
                    'visible' => $visible,
                    'lastRadarNumber' => $lastRadarNumber,
                    'replace' => $replace,
                ] = $this->tryRemovePoints(
                    counter: $visibleCounter,
                    lastRadarNumber: $lastRadarNumber,
                    visorCoordinate: $visorCoordinate,
                    prevRadar: $prevRadar,
                    currentRadar: $currentRadar,
                );
                //todo если мы в невидимой части, то перемещаясь назад не можем оказаться в видимой
            } else {
                $replace = new ReplaceRadarCounter();
            }


            $prevRadar = $currentRadar;
            array_splice($visibleCounter, $replace->offset, $replace->length, $replace->radars);
        }

        return array_column($visibleCounter, 'coordinate');
    }

    private function getAngle(Point2D $visorCoordinate, Point2D $vertexCoordinate): Angle
    {
        return new Angle(atan2(
            $visorCoordinate->y - $vertexCoordinate->y,
            $visorCoordinate->x - $vertexCoordinate->x,
        ));
    }

    private function getDistance(Point2D $visorCoordinate, Point2D $vertexCoordinate): float
    {
        return ($visorCoordinate->y - $vertexCoordinate->y) * ($visorCoordinate->y - $vertexCoordinate->y)
            + ($visorCoordinate->x - $vertexCoordinate->x) * ($visorCoordinate->x - $vertexCoordinate->x);
    }

    /**
     * @param Radar[] $counter
     *
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     */
    private function tryAddPoint(
        array $counter,
        ?int $lastConnectedNumber,
        Point2D $visorCoordinate,
        Radar $prevRadar,
        Radar $currentRadar,
    ): array {
        //visor не часть многоугольника
        $this->getCross(true, $counter, $prevRadar, $currentRadar);

        $firstVisibleRadar = $counter[0] ?? null;

        if ($firstVisibleRadar === null || $firstVisibleRadar->angle->greaterThanOrEqual($currentAngle)) {
            return $this->newLineBeforeCounter($prevRadar, $currentCoordinate, $currentAngle, $currentDistance);
        }

        $count = count($counter);
        $lastVisibleRadar = $counter[$count - 1];

        if (
            $firstVisibleRadar->angle->greaterThan($prevRadar->angle)
            && $lastVisibleRadar->angle->greaterThanOrEqual($currentAngle)
        ) {
            return $this->newLineCrossLeftCounter(
                $counter,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $firstVisibleRadar->angle->greaterThan($prevRadar->angle)
            && $currentAngle->isEqual($lastVisibleRadar->angle)
        ) {
            return $this->newLineIncludeAllCounter(
                $counter,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $firstVisibleRadar->angle->isEqual($prevRadar->angle)
            && $lastVisibleRadar->angle->greaterThan($currentAngle)
        ) {
            return $this->newLineContainLeftCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $firstVisibleRadar->angle->isEqual($prevRadar->angle)
            && $lastVisibleRadar->angle->isEqual($currentAngle)
        ) {
            // ---
            // ~~~
            return $this->newLineEqualCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $firstVisibleRadar->angle->isEqual($prevRadar->angle)
            && $currentAngle->greaterThan($lastVisibleRadar->angle)
        ) {
            // ----
            // ~~~
            return $this->newLineIncludeRightCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $prevRadar->angle->greaterThan($firstVisibleRadar->angle)
            && $lastVisibleRadar->angle->greaterThan($currentAngle)
        ) {
            //  -
            // ~~~
            return $this->newLineInsideCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $prevRadar->angle->greaterThan($firstVisibleRadar->angle)
            && $lastVisibleRadar->angle->isEqual($currentAngle)
        ) {
            //   -
            // ~~~
            return $this->newLineContainRightCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if (
            $prevRadar->angle->greaterThan($firstVisibleRadar->angle)
            && $currentAngle->greaterThan($lastVisibleRadar->angle)
        ) {
            //   --
            // ~~~
            return $this->newLineCrossRightCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if ($prevRadar->angle->isEqual($lastVisibleRadar->angle)) {
            // ~~~-
            return $this->newLineConnectRightCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        if ($prevRadar->angle->greaterThan($lastVisibleRadar->angle)) {
            // ~~~ -
            return $this->newLineAfterCounter(
                $counter,
                $lastConnectedNumber,
                $visorCoordinate,
                $prevRadar,
                $currentCoordinate,
                $currentAngle,
                $currentDistance,
            );
        }

        throw new LogicException('Unexpected case.');
    }

    /**
     * @param Radar[] $counter
     *
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     */
    private function tryRemovePoints(
        array $counter,
        ?int $lastRadarNumber,
        Point2D $visorCoordinate,
        Radar $prevRadar,
        Radar $currentRadar,
    ): array {



        return [
            'prevRadar' => $resultRadar,
            'visible' => $visible,
            'lastRadarNumber' => $resultRadarNumber,
            'replace' => $replace,
        ];
    }

    private function getCrossCoordinate(Line2D $lineA, Line2D $lineB, bool $real = false): ?Point2D
    {
        // Координаты точек для первой линии
        $x1 = $lineA->pointA->x;
        $y1 = $lineA->pointA->y;
        $x2 = $lineA->pointB->x;
        $y2 = $lineA->pointB->y;

        // Координаты точек для второй линии
        $x3 = $lineB->pointA->x;
        $y3 = $lineB->pointA->y;
        $x4 = $lineB->pointB->x;
        $y4 = $lineB->pointB->y;

        $denominator = ($x1 - $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 - $x4);

        if ($denominator == 0) {
            return null;
        }

        $result = new Point2D(
            (($x1 * $y2 - $y1 * $x2) * ($x3 - $x4) - ($x1 - $x2) * ($x3 * $y4 - $y3 * $x4)) / $denominator,
            (($x1 * $y2 - $y1 * $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 * $y4 - $y3 * $x4)) / $denominator,
        );

        return !$real
            || (min($x1, $x2) <= $result->x && max($x1, $x2) >= $result->x)
            || (min($y1, $y2) <= $result->y && max($y1, $y2) >= $result->y)
                ? $result
                : null;
    }


    /**
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     *
     * [- ~~~ | -~~~]
     */
    private function newLineBeforeCounter(
        Radar $prevRadar,
        Point2D $currentCoordinate,
        Angle $currentAngle,
        float $currentDistance,
    ): array {
        $newRadar = new Radar(
            coordinate: $currentCoordinate,
            angle: $currentAngle,
            distance: $currentDistance,
            connect: true,
        );

        return [
            'newRadar' => $newRadar,
            'visible' => true,
            'lastRadarNumber' => 1,
            'replace' => new ReplaceRadarCounter(0, 0, [$prevRadar, $newRadar]),
        ];
    }

    /**
     * @param Radar[] $counter
     *
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     *
     * [ --   | ---- ]
     * [  ~~~ |  ~~~ ]
     */
    private function newLineCrossLeftCounter(
        array $counter,
        Point2D $visorCoordinate,
        Radar $prevRadar,
        Point2D $currentCoordinate,
        Angle $currentAngle,
        float $currentDistance,
    ): array {

        $cross = $this->getCross($counter, $prevRadar, $currentCoordinate, $currentAngle, $visorCoordinate);

        $crossCoordinate = $this->getCrossCoordinate(
            new Line2D($visorCoordinate, $counter[0]->coordinate),
            new Line2D($prevRadar->coordinate, $currentCoordinate),
        );
        /** Расстояние до отрезка через первую координату контура */
        $crossDistance = $this->getDistance($visorCoordinate, $crossCoordinate);

        if ($crossDistance > $counter[0]->distance) {
            $crossRadar = new Radar(
                coordinate: $crossCoordinate,
                angle: $counter[0]->angle,
                distance: $crossDistance,
                connect: true,
            );
            $newRadar = new Radar(
                coordinate: $currentCoordinate,
                angle: $currentAngle,
                distance: $currentDistance,
                connect: false,
            );

            return [
                'newRadar' => $newRadar,
                'visible' => false,
                'lastRadarNumber' => null,
                'replace' => new ReplaceRadarCounter(0, 0, [$prevRadar, $crossRadar]),
            ];
        }

        //пересечение контура возможно (контур ведем прямо, в какой то-момент уходим чуть назад, создавая Z образный выступ, в который может провалиться часть отрезка)
        //todo нужен объект пересечения - данные о том, между какими двумя точками контура пересечение и в какой координате

        $cross = $this->getCross($counter, $prevRadar, $currentCoordinate, $currentAngle, $visorCoordinate);

        if ($cross->crossCounterNumber !== null) {
            //Имеется пересечение контура - часть нового отрезка скрыто за бесконтактной частью контура
            $crossCoordinate = $this->getCrossCoordinate(
                new Line2D($visorCoordinate, $counter[$cross->crossCounterNumber]->coordinate),
                new Line2D($prevRadar->coordinate, $currentCoordinate),
                true,
            );

            if ($crossCoordinate === null) {
                $newRadar = new Radar(
                    coordinate: $currentCoordinate,
                    angle: $currentAngle,
                    distance: $currentDistance,
                    connect: true,
                );

                return [
                    'newRadar' => $newRadar,
                    'visible' => false,
                    'lastRadarNumber' => null,
                    'replace' => new ReplaceRadarCounter(0, $cross->crossCounterNumber, [$prevRadar, $newRadar]),
                ];
            }

            $crossRadar = new Radar(
                coordinate: $crossCoordinate,
                angle: $this->getAngle($visorCoordinate, $crossCoordinate),
                distance: $this->getDistance($visorCoordinate, $crossCoordinate),
                connect: true,
            );
            $newRadar = new Radar(
                coordinate: $currentCoordinate,
                angle: $currentAngle,
                distance: $currentDistance,
                connect: false,
            );

            return [
                'newRadar' => $newRadar,
                'visible' => false,
                'lastRadarNumber' => null,
                'replace' => new ReplaceRadarCounter(0, $cross->crossCounterNumber, [$prevRadar, $crossRadar]),
            ];
        }

        if ($cross->lastCounterNumber === null) {
            throw new LogicException('Отрезок в этом методе не должен быть длинее видимого контура.');
        }

        $newRadar = new Radar(
            coordinate: $currentCoordinate,
            angle: $currentAngle,
            distance: $currentDistance,
            connect: true,
        );

        return [
            'newRadar' => $newRadar,
            'visible' => false,
            'lastRadarNumber' => null,
            'replace' => new ReplaceRadarCounter(0, $cross->lastCounterNumber, [$prevRadar, $newRadar]),
        ];
    }

    /**
     * @param Radar[] $counter
     *
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     *
     * [ ----- ] -
     * [  ~~~  ]
     */
    private function newLineIncludeAllCounter(
        array $counter,
        Point2D $visorCoordinate,
        Radar $prevRadar,
        Point2D $currentCoordinate,
        Angle $currentAngle,
        float $currentDistance,
    ): array {
        $crossCoordinate = $this->getCrossCoordinate(
            new Line2D($visorCoordinate, $counter[0]->coordinate),
            new Line2D($prevRadar->coordinate, $currentCoordinate),
        );
        $crossDistance = $this->getDistance($visorCoordinate, $crossCoordinate);
        $count = count($counter);

        if ($crossDistance < $counter[0]->distance) {
            $newRadar = new Radar(
                coordinate: $currentCoordinate,
                angle: $currentAngle,
                distance: $currentDistance,
                connect: true,
            );

            return [
                'newRadar' => $newRadar,
                'visible' => true,
                'lastRadarNumber' => 1,
                'replace' => new ReplaceRadarCounter(0, $count, [$prevRadar, $newRadar]),
            ];
        }

        $newRadar = new Radar(
            coordinate: $currentCoordinate,
            angle: $currentAngle,
            distance: $currentDistance,
            connect: true,
        );
        $rightCrossCoordinate = $this->getCrossCoordinate(
            new Line2D($visorCoordinate, $counter[$count - 1]->coordinate),
            new Line2D($prevRadar->coordinate, $currentCoordinate),
        );

        return [
            'newRadar' => $newRadar,
            'visible' => true,
            'lastRadarNumber' => $count + 3,
            'replace' => new ReplaceRadarCounter(0, count($counter), [
                $prevRadar,
                new Radar(
                    coordinate: $crossCoordinate,
                    angle: $counter[0]->angle,
                    distance: $crossDistance,
                    connect: true,
                ),
                ...$counter,
                new Radar(
                    coordinate: $rightCrossCoordinate,
                    angle: $counter[$count - 1]->angle,
                    distance: $this->getDistance($visorCoordinate, $rightCrossCoordinate),
                    connect: true,
                ),
                $newRadar,
            ]),
        ];
    }

    /**
     * @param Radar[] $counter
     *
     * @return array{prevRadar: Radar, visible: bool, lastRadarNumber: int}
     *
     * [ -   ]
     * [ ~~~ ]
     */
    private function newLineContainLeftCounter(
        array $counter,
        ?int $lastConnectedNumber,
        Point2D $visorCoordinate,
        Radar $prevRadar,
        Point2D $currentCoordinate,
        Angle $currentAngle,
        float $currentDistance,
    ): array {
        //если $lastConnectedNumber !== null, отрезок может пересекать контур
        $cross = $lastConnectedNumber === null ? null : $this->getCross()

        if ($prevRadar->distance > $counter[0]->distance) {
            return [
                'newRadar' => new Radar(
                    coordinate: $currentCoordinate,
                    angle: $currentAngle,
                    distance: $currentDistance,
                    connect: false,
                ),
                'visible' => false,
                'lastRadarNumber' => null,
                'replace' => new ReplaceRadarCounter(),
            ];
        }

        $prevCounterRadar = null;

        foreach ($counter as $number => $radar) {
            if ($prevCounterRadar === null) {
                $prevCounterRadar = $radar;

                continue;
            }

            if ($radar->angle->isEqual($currentAngle)) {
                if ($currentDistance > $radar->distance) {
                    throw new LogicException('Отрезок, начинающийся до контура не может пересекать контур.');
                }

                $newRadar = new Radar(
                    coordinate: $currentCoordinate,
                    angle: $currentAngle,
                    distance: $currentDistance,
                    connect: true,
                );

                return [
                    'newRadar' => $newRadar,
                    'visible' => true,
                    'lastRadarNumber' => 1,
                    'replace' => new ReplaceRadarCounter(0, $number + 1, [$prevRadar, $newRadar]),
                ];
            }

            if ($radar->angle->greaterThan($currentAngle)) {
                $crossCoordinate = $this->getCrossCoordinate(
                    new Line2D($visorCoordinate, $currentCoordinate),
                    new Line2D($prevCounterRadar->coordinate, $radar->coordinate),
                );
                $crossDistance = $this->getDistance($visorCoordinate, $crossCoordinate);

                if ($currentDistance > $crossDistance) {
                    throw new LogicException('Отрезок, начинающийся до контура не может пересекать контур.');
                }

                $crossRadar = new Radar(
                    coordinate: $crossCoordinate,
                    angle: $currentAngle,
                    distance: $crossDistance,
                    connect: true,
                );
                $newRadar = new Radar(
                    coordinate: $currentCoordinate,
                    angle: $currentAngle,
                    distance: $currentDistance,
                    connect: false,
                );

                return [
                    'newRadar' => $newRadar,
                    'visible' => true,
                    'lastRadarNumber' => 1,
                    'replace' => new ReplaceRadarCounter(0, $number + 1, [$prevRadar, $newRadar, $crossRadar]),
                ];
            }

            $prevCounterRadar = $radar;
        }

    }

    /**
     * @param Radar[] $counter
     */
    private function getCross(
        bool $asc,
        array $counter,//полигон
        Radar $prevRadar,
        Radar $currentRadar,
    ): Cross {
        if ($counter === []) {
            return new Cross(first: new CrossPoint(number: 0, current: true, isEqual: false));
        }

        $firstCounter = $counter[0];
        $count = count($counter);
        $lastCounter = $counter[$count - 1];
        $intersect = $asc
            ? $this->getAnglesIntersect($prevRadar->angle, $currentRadar->angle, $firstCounter->angle, $lastCounter->angle)
            : $this->getAnglesIntersect($currentRadar->angle, $prevRadar->angle, $firstCounter->angle, $lastCounter->angle);




        if ($asc && $prevRadar->angle > $currentRadar->angle) {
            //идем по часовой стрелке, при этом предыдущая точка лежит впереди - значит пересекли нулевой угол
            if ($firstRadar->angle > $lastRadar->angle) {
                //контур пересек нулевой угол

                return;
            }



            return;
        }

        if (!$asc && $prevRadar->angle < $currentRadar->angle) {
            //идем против часовой стрелке, при этом предыдущая точка лежит позади - значит пересекли нулевой угол

            return;
        }

        $firstRadar = $counter[0];
        $lastRadar = $counter[count($counter) - 1];

        if ($firstRadar->angle > $lastRadar->angle) {
            //контур пересек нулевой угол

            return;
        }

        if ($asc) {
            if ($firstRadar->angle->greaterThan($currentRadar->angle)) {
                return new Cross(first: new CrossPoint(number: 0, current: true, isEqual: false));
            }

            if ($firstRadar->angle->isEqual($currentRadar->angle)) {
                return new Cross(first: new CrossPoint(number: 0, current: true, isEqual: true));
            }

            if ($prevRadar->angle->greaterThan($lastRadar->angle)) {
                return new Cross(first: new CrossPoint(number: 0, current: true, isEqual: false));
            }

            if ($prevRadar->angle->isEqual($lastRadar->angle)) {
                return new Cross(
                    first: new CrossPoint(number: 0, current: true, isEqual: false),
                    last: new CrossPoint(number: 0, current: false, isEqual: true),
                );
            }
        }



        //$counter[0]->angle->between()

        if (
            $prevRadar->angle->greaterThan($lastRadar->angle)
        ) {
            return new Cross(first: new CrossPoint(number: 0, current: true, isEqual: false));
        }

        if ($counter[0]->angle->isEqual($currentRadar->angle)) {
            return new Cross(last: new CrossPoint(number: 0, current: true, isEqual: true));
        }





        //пересечение контура может быть только в точках разрыва.
        $prevCounterRadar = null;
        $firstPoint = null;
        $firstIsEqual = false;
        $lastCounterNumber = null;
        $lastIsEqual = false;
        $crossCoordinate = null;
        $crossCounterNumber = null;
        $crossIsEqual = false;

        foreach ($counter as $number => $radar) {
            if ($radar->angle->greaterThan($prevRadar->angle)) {
                $prevCounterRadar = $radar;

                continue;
            }

            if ($firstPoint === null) {
                $firstPoint = new CrossPoint(
                    number: $number,
                    current:
                );
            }

            $firstPoint ??= $number;

            if ($radar->angle->greaterThanOrEqual($currentAngle)) {
                $lastCounterNumber = $number;
            }

            if ($radar->connect) {
                $prevCounterRadar = $radar;

                if ($lastCounterNumber !== null) {
                    break;
                }

                continue;
            }

            if ($crossCoordinate === null) {
                $crossCoordinate = $this->getCrossCoordinate(
                    new Line2D($radar->coordinate, $prevCounterRadar->coordinate),
                    new Line2D($currentCoordinate, $prevRadar->coordinate),
                    true,
                );

                if ($crossCoordinate !== null) {
                    $crossCounterNumber = $number;
                }
            }

            if ($lastCounterNumber !== null) {
                break;
            }

            $prevCounterRadar = $radar;
        }

        return new Cross(
            firstCounterNumber: $firstPoint,
            lastCounterNumber: $lastCounterNumber,
            crossCounterNumber: $crossCounterNumber,
        );
    }

    private function getAnglesIntersect(Angle $angle1L, Angle $angle1R, Angle $angle2L, Angle $angle2R): IntersectType
    {
        $angle1LRelation = $this->getAngleRelation($angle1L, $angle2L, $angle2R);
        $angle1RRelation = $this->getAngleRelation($angle1R, $angle2L, $angle2R);



        //Both
        if ($angle2R->greaterThan($angle2L)) {
            $invert = Angle::pi()->greaterThan($angle2R->minus($angle2R));
        } else {

        }



        return match ($angle1LRelation) {
            AngleIntersectType::EqualLeft => match ($angle1RRelation) {
                AngleIntersectType::EqualRight => IntersectType::Equals,
                AngleIntersectType::Between => IntersectType::Contain,
                AngleIntersectType::Outside => IntersectType::Absorption,
                default => throw new LogicException(),
            },
            AngleIntersectType::Between => match ($angle1RRelation) {
                AngleIntersectType::EqualRight,
                AngleIntersectType::Between =>  ? IntersectType::Contain : IntersectType::BothIntersect,
                AngleIntersectType::EqualLeft,
                AngleIntersectType::Outside => IntersectType::LeftIntersect,
            },
            AngleIntersectType::Outside, AngleIntersectType::EqualRight => match ($angle1RRelation) {
                AngleIntersectType::EqualRight => IntersectType::Absorption,
                AngleIntersectType::Between => IntersectType::RightIntersect,
                AngleIntersectType::EqualLeft,
                AngleIntersectType::Outside => IntersectType::NotIntersect,
            },
        };
    }

    private function shift(mixed $case, int $pos): array
    {
        return array_push($case, ...array_splice($case, 0, $pos));
    }

    private function getAngleRelation(Angle $angle, Angle $angleA, Angle $angleB): AngleIntersectType
    {
        if ($angle->isEqual($angleA)) {
            return AngleIntersectType::EqualLeft;
        }

        if ($angle->isEqual($angleB)) {
            return AngleIntersectType::EqualRight;
        }

        if (
            $angle->greaterThan($angleA) && $angleB->greaterThan($angle)
            || ($angleA->greaterThan($angleB) && ($angleA->greaterThan($angle) || $angle->greaterThan($angleB)))
        ) {
            return AngleIntersectType::Between;
        }

        return AngleIntersectType::Outside;
    }
}