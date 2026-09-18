<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Services\GeometryService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeometryServiceTest extends TestCase
{
    private const float DELTA = 1.0e-6;

    public function testDoubleAreaIsPositiveForCounterClockwiseOrder(): void
    {
        $square = self::polygon([0, 0], [10, 0], [10, 10], [0, 10]);

        self::assertSame(200.0, $this->service()->doubleArea($square));
        self::assertSame(-200.0, $this->service()->doubleArea(array_reverse($square)));
    }

    public function testCentroidOfSquareIsItsCenter(): void
    {
        $centroid = $this->service()->centroid(self::polygon([0, 0], [10, 0], [10, 10], [0, 10]));

        self::assertEqualsWithDelta(5.0, $centroid->x, self::DELTA);
        self::assertEqualsWithDelta(5.0, $centroid->y, self::DELTA);
    }

    /**
     * Центр масс площади не то же самое, что среднее по вершинам: сгущение
     * вершин в одном углу среднее сдвигает, а центр масс — нет.
     */
    public function testCentroidIgnoresVertexDensity(): void
    {
        $square = self::polygon([0, 0], [5, 0], [10, 0], [10, 10], [0, 10]);
        $service = $this->service();

        self::assertEqualsWithDelta(5.0, $service->centroid($square)->x, self::DELTA);
        self::assertEqualsWithDelta(5.0, $service->centroid($square)->y, self::DELTA);
        self::assertEqualsWithDelta(5.0, $service->averagePoint($square)->x, self::DELTA);
        self::assertEqualsWithDelta(4.0, $service->averagePoint($square)->y, self::DELTA);
    }

    public function testCentroidOfDegeneratePolygonFallsBackToAverage(): void
    {
        $centroid = $this->service()->centroid(self::polygon([0, 0], [10, 0], [20, 0]));

        self::assertEqualsWithDelta(10.0, $centroid->x, self::DELTA);
        self::assertEqualsWithDelta(0.0, $centroid->y, self::DELTA);
    }

    public function testCentroidOfEmptyPolygonFails(): void
    {
        $this->expectException(LogicException::class);

        $this->service()->centroid([]);
    }

    public function testDistance(): void
    {
        self::assertSame(5.0, $this->service()->distance(new Point2D(0, 0), new Point2D(3, 4)));
        self::assertSame(25.0, $this->service()->squaredDistance(new Point2D(0, 0), new Point2D(3, 4)));
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @param array{float, float} $c
     * @param array{float, float} $d
     */
    #[DataProvider('getSegmentsData')]
    public function testSegmentsIntersect(array $a, array $b, array $c, array $d, bool $expected): void
    {
        self::assertSame($expected, $this->service()->segmentsIntersect(
            new Point2D(...$a),
            new Point2D(...$b),
            new Point2D(...$c),
            new Point2D(...$d),
        ));
    }

    /**
     * @return array<string, array{array{float, float}, array{float, float}, array{float, float}, array{float, float}, bool}>
     */
    public static function getSegmentsData(): array
    {
        return [
            'крест' => [[0.0, 0.0], [10.0, 10.0], [0.0, 10.0], [10.0, 0.0], true],
            'мимо' => [[0.0, 0.0], [10.0, 0.0], [0.0, 5.0], [10.0, 5.0], false],
            'общий конец не пересечение' => [[0.0, 0.0], [10.0, 0.0], [10.0, 0.0], [10.0, 10.0], false],
            'касание концом не пересечение' => [[0.0, 0.0], [10.0, 0.0], [5.0, 0.0], [5.0, 10.0], false],
            'коллинеарные не пересечение' => [[0.0, 0.0], [10.0, 0.0], [5.0, 0.0], [15.0, 0.0], false],
            'продолжение не пересечение' => [[0.0, 0.0], [5.0, 5.0], [6.0, 6.0], [10.0, 0.0], false],
        ];
    }

    public function testIsPointInPolygon(): void
    {
        $square = self::polygon([0, 0], [10, 0], [10, 10], [0, 10]);
        $service = $this->service();

        self::assertTrue($service->isPointInPolygon(new Point2D(5, 5), $square));
        self::assertFalse($service->isPointInPolygon(new Point2D(15, 5), $square));
        self::assertFalse($service->isPointInPolygon(new Point2D(0, 5), $square), 'точка на границе не внутри');
        self::assertFalse($service->isPointInPolygon(new Point2D(0, 0), $square), 'вершина не внутри');
    }

    public function testIsPointInNonConvexPolygon(): void
    {
        // Буква L: выемка в правом верхнем углу.
        $shape = self::polygon([0, 0], [10, 0], [10, 4], [4, 4], [4, 10], [0, 10]);
        $service = $this->service();

        self::assertTrue($service->isPointInPolygon(new Point2D(2, 2), $shape));
        self::assertTrue($service->isPointInPolygon(new Point2D(8, 2), $shape));
        self::assertFalse($service->isPointInPolygon(new Point2D(8, 8), $shape), 'выемка снаружи фигуры');
    }

    public function testVisibilityPolygonOfConvexShapeIsTheShapeItself(): void
    {
        $square = self::polygon([0, 0], [10, 0], [10, 10], [0, 10]);

        $visible = $this->service()->visibilityPolygon(new Point2D(5, 5), $square);

        self::assertEqualsWithDelta(100.0, abs($this->service()->doubleArea($visible)) / 2, 0.05);
    }

    /**
     * Из выпуклой фигуры видно всё, из невыпуклой — не всё:
     * угол загораживает часть площади.
     */
    public function testVisibilityPolygonIsSmallerBehindCorner(): void
    {
        $shape = self::polygon([0, 0], [10, 0], [10, 4], [4, 4], [4, 10], [0, 10]);
        $service = $this->service();

        $visible = $service->visibilityPolygon(new Point2D(1, 8), $shape);
        $visibleArea = abs($service->doubleArea($visible)) / 2;
        $shapeArea = abs($service->doubleArea($shape)) / 2;

        self::assertGreaterThan(0.0, $visibleArea);
        self::assertLessThan($shapeArea, $visibleArea);
        self::assertFalse(
            $service->isPointInPolygon(new Point2D(9, 1), $visible),
            'дальний угол за выемкой не должен быть виден',
        );
    }

    public function testVisibilityPolygonOfDegenerateShapeIsEmpty(): void
    {
        self::assertSame([], $this->service()->visibilityPolygon(new Point2D(0, 0), []));
        self::assertSame([], $this->service()->visibilityPolygon(new Point2D(0, 0), self::polygon([0, 1], [1, 1])));
    }

    public function testClipToPolygonStopsBeforeWall(): void
    {
        $square = self::polygon([0, 0], [10, 0], [10, 10], [0, 10]);
        $service = $this->service();

        $inside = $service->clipToPolygon(new Point2D(5, 5), new Point2D(7, 5), $square);
        $clipped = $service->clipToPolygon(new Point2D(5, 5), new Point2D(100, 5), $square);

        self::assertEqualsWithDelta(7.0, $inside->x, self::DELTA, 'цель внутри не двигается');
        self::assertLessThan(10.0, $clipped->x);
        self::assertGreaterThan(5.0, $clipped->x);
        self::assertTrue($service->isPointInPolygon($clipped, $square));
    }

    public function testClipToPolygonKeepsOriginWhenTargetIsTheSamePoint(): void
    {
        $origin = new Point2D(5, 5);
        $square = self::polygon([0, 0], [10, 0], [10, 10], [0, 10]);

        self::assertSame($origin, $this->service()->clipToPolygon($origin, $origin, $square));
    }

    private function service(): GeometryService
    {
        return new GeometryService();
    }

    /**
     * @param array{int|float, int|float} ...$points
     *
     * @return Point2D[]
     */
    private static function polygon(array ...$points): array
    {
        return array_map(static fn (array $point): Point2D => new Point2D((float) $point[0], (float) $point[1]), $points);
    }
    /**
     * Расстояние до отрезка, а не до прямой: за концом отрезка меряется
     * до самого конца.
     */
    public function testDistanceToSegment(): void
    {
        $service = $this->service();
        $from = new Point2D(0, 0);
        $to = new Point2D(100, 0);

        self::assertEqualsWithDelta(5.0, $service->distanceToSegment(new Point2D(50, 5), $from, $to), 1.0e-9);
        self::assertEqualsWithDelta(0.0, $service->distanceToSegment(new Point2D(50, 0), $from, $to), 1.0e-9);
        self::assertEqualsWithDelta(10.0, $service->distanceToSegment(new Point2D(110, 0), $from, $to), 1.0e-9);
        self::assertEqualsWithDelta(
            sqrt(200),
            $service->distanceToSegment(new Point2D(110, 10), $from, $to),
            1.0e-9,
        );
        // Вырожденный отрезок — это точка.
        self::assertEqualsWithDelta(3.0, $service->distanceToSegment(new Point2D(3, 0), $from, $from), 1.0e-9);
    }

}
