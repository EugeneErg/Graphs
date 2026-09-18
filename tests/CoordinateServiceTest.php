<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Services\CoordinateService;
use EugeneErg\Graphs\Services\GeometryService;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityVertexes;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Topology;
use PHPUnit\Framework\Attributes\DataProvider;

final class CoordinateServiceTest extends AbstractTestCase
{
    private const float DELTA = 1.0e-6;

    public function testTangledCoordinatesLieOnCircleInVertexOrder(): void
    {
        $coordinates = $this->getCoordinateService()->getTangledCoordinates([2, 0, 1, 3], 100.0);
        $geometry = $this->getGeometryService();
        $center = new Point2D();

        self::assertSame([0, 1, 2, 3], array_keys($coordinates));

        foreach ($coordinates as $point) {
            self::assertEqualsWithDelta(100.0, $geometry->distance($center, $point), self::DELTA);
        }
    }

    public function testTangledCoordinatesOfSingleVertexSitInCenter(): void
    {
        $coordinates = $this->getCoordinateService()->getTangledCoordinates([7], 100.0);

        self::assertEquals([7 => new Point2D()], $coordinates);
    }

    public function testIndexesSpreadFromStartToEnd(): void
    {
        $arc = new Arc(new GravityVertexes(0), [[1, 2, 3]]);

        self::assertSame([1 => 0.0, 2 => 0.5, 3 => 1.0], $this->getCoordinateService()->getIndexes($arc));
    }

    /**
     * Дуга из одной точки не имеет протяжённости — точка встаёт посередине,
     * а не делится на ноль.
     */
    public function testIndexesOfSinglePointArc(): void
    {
        $arc = new Arc(new GravityVertexes(0), [[5]]);

        self::assertSame([5 => 0.5], $this->getCoordinateService()->getIndexes($arc));
    }

    public function testBarycentricKeepsOuterVertexesAndAveragesInnerOnes(): void
    {
        // Квадрат 1,2,3,4 с вершиной 0 в середине: она обязана встать в центр.
        $outer = new Edge([1, 2, 3, 4]);
        $faces = [new Edge([1, 2, 0]), new Edge([2, 3, 0]), new Edge([3, 4, 0]), new Edge([4, 1, 0])];
        $coordinates = [
            1 => new Point2D(-100, -100),
            2 => new Point2D(100, -100),
            3 => new Point2D(100, 100),
            4 => new Point2D(-100, 100),
            0 => new Point2D(80, 90),
        ];

        $actual = $this->getCoordinateService()->getBarycentricCoordinates($outer, $faces, $coordinates);

        self::assertEquals($coordinates[1], $actual[1]);
        self::assertEquals($coordinates[3], $actual[3]);
        self::assertEqualsWithDelta(0.0, $actual[0]->x, 0.05);
        self::assertEqualsWithDelta(0.0, $actual[0]->y, 0.05);
    }

    #[DataProvider('getGraphNames')]
    public function testRelaxKeepsEveryFramePlanar(string $name): void
    {
        [$outerEdge, $faces, $frames] = $this->relax($name);
        $connections = self::facesToConnections(array_merge([$outerEdge], $faces));

        foreach ($frames as $number => $frame) {
            self::assertSame(
                0,
                self::countCrossings($connections, $frame),
                sprintf('Кадр %d укладки %s не плоский.', $number, $name),
            );
        }
    }

    #[DataProvider('getGraphNames')]
    public function testRelaxKeepsOuterVertexesInPlace(string $name): void
    {
        [$outerEdge, , $frames] = $this->relax($name);
        $first = $frames[0];
        $last = $frames[count($frames) - 1];

        foreach ($outerEdge->vertexes as $vertex) {
            self::assertEquals($first[$vertex], $last[$vertex], sprintf('Вершина %d внешней грани сдвинулась.', $vertex));
        }
    }

    /**
     * Расслабление обязано делать укладку читаемее, а не теснее: и вершины
     * друг от друга, и вершины от чужих рёбер.
     */
    #[DataProvider('getGraphNames')]
    public function testRelaxNeverMakesLayoutTighter(string $name): void
    {
        [$outerEdge, $faces, $frames] = $this->relax($name);
        $connections = self::facesToConnections(array_merge([$outerEdge], $faces));

        $before = self::getReadability($frames[0], $connections);
        $after = self::getReadability($frames[count($frames) - 1], $connections);

        self::assertGreaterThanOrEqual($before - self::DELTA, $after);
    }

    /**
     * Вершина не должна лежать на чужом ребре: пересечения нет, а выглядит
     * как пересечение.
     */
    #[DataProvider('getGraphNames')]
    public function testRelaxTakesVertexesOffForeignEdges(string $name): void
    {
        [$outerEdge, $faces, $frames] = $this->relax($name);
        $connections = self::facesToConnections(array_merge([$outerEdge], $faces));
        $last = $frames[count($frames) - 1];
        $distance = self::getMinVertexDistance($last);

        if ($distance === .0 || $distance === INF) {
            self::markTestSkipped('В укладке нет двух вершин.');
        }

        // Просвет до чужого ребра соизмерим с расстоянием между вершинами:
        // вершина стоит в стороне от ребра, а не на нём.
        self::assertGreaterThan(
            $distance / 10,
            self::getReadability($last, $connections),
            sprintf('В укладке %s вершина прижата к чужому ребру.', $name),
        );
    }

    #[DataProvider('getGraphNames')]
    public function testRelaxKeepsVertexSet(string $name): void
    {
        [, , $frames] = $this->relax($name);
        $expected = array_keys($frames[0]);

        foreach ($frames as $frame) {
            self::assertSame($expected, array_keys($frame));
        }
    }

    public function testRelaxIsDeterministic(): void
    {
        [$outerEdge, $faces, $frames] = $this->relax('Big1');
        $repeated = $this->getCoordinateService()->relaxSteps($outerEdge, $faces, $frames[0]);

        self::assertEquals($frames, $repeated);
    }

    public function testRelaxCoordinatesReturnsLastStep(): void
    {
        [$outerEdge, $faces, $frames] = $this->relax('TriangleInTriangle');

        $actual = $this->getCoordinateService()->relaxCoordinates($outerEdge, $faces, $frames[0]);

        self::assertEquals($frames[count($frames) - 1], $actual);
    }

    public function testRelaxOfLayoutWithoutInnerVertexesChangesNothing(): void
    {
        $outerEdge = new Edge([0, 1, 2]);
        $coordinates = [
            0 => new Point2D(0, -100),
            1 => new Point2D(86.6, 50),
            2 => new Point2D(-86.6, 50),
        ];

        $frames = $this->getCoordinateService()->relaxSteps($outerEdge, [$outerEdge], $coordinates);

        self::assertSame([$coordinates], $frames);
    }

    /**
     * Расслабление не должно занимать заметное время на графах такого размера.
     */
    public function testRelaxOfBigGraphIsFast(): void
    {
        $service = new CoordinateService(new GeometryService(), 200);
        [$outerEdge, $faces, $frames] = $this->relax('Big1');

        $start = microtime(true);
        $service->relaxSteps($outerEdge, $faces, $frames[0]);

        self::assertLessThan(3.0, microtime(true) - $start);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function getGraphNames(): array
    {
        return [
            'треугольник' => ['SimpleTriangle'],
            'прямоугольник' => ['SimpleRectangle'],
            'треугольник в треугольнике' => ['TriangleInTriangle'],
            'три вложенных треугольника' => ['TriangleInTriangleInTriangle'],
            'большой граф' => ['Big1'],
            'большой несвязный граф с перешейками' => ['Big2'],
            'дерево с циклом' => ['SmallTree'],
        ];
    }

    /**
     * @return array{Edge, Edge[], Point2D[][]}
     */
    private function relax(string $name): array
    {
        $connections = require __DIR__ . '/Cases/Graphs/' . $name . '.php';
        [$outerEdge, $faces] = $this->getFaces($connections);
        $service = $this->getCoordinateService();
        $arcs = $this->getArcService()->createArcs($faces, $outerEdge);
        $coordinates = $service->getBarycentricCoordinates(
            $outerEdge,
            $faces,
            $service->getCoordinates(new Topology($outerEdge, $arcs), 100.0),
        );

        return [$outerEdge, $faces, $service->relaxSteps($outerEdge, $faces, $coordinates)];
    }
}
