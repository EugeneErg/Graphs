<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Services\GeometryService;
use EugeneErg\Graphs\Services\MotionService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Планарность нужна не только в кадрах, но и всё время между ними:
 * картинка проигрывает переход по прямой.
 */
final class MotionServiceTest extends AbstractTestCase
{
    public function testStaticCrossingIsFound(): void
    {
        self::assertTrue($this->getGeometryService()->segmentsIntersectDuringMotion(
            new Point2D(0, 0),
            new Point2D(0, 0),
            new Point2D(10, 10),
            new Point2D(10, 10),
            new Point2D(0, 10),
            new Point2D(0, 10),
            new Point2D(10, 0),
            new Point2D(10, 0),
        ));
    }

    public function testStaticSegmentsApartDoNotCross(): void
    {
        self::assertFalse($this->getGeometryService()->segmentsIntersectDuringMotion(
            new Point2D(0, 0),
            new Point2D(0, 0),
            new Point2D(10, 0),
            new Point2D(10, 0),
            new Point2D(0, 5),
            new Point2D(0, 5),
            new Point2D(10, 5),
            new Point2D(10, 5),
        ));
    }

    /**
     * Главный случай: оба кадра чистые, а по дороге отрезок проходит насквозь.
     * Проверка только по кадрам такое пропускает.
     */
    public function testCrossingThatHappensOnlyInTheMiddleIsFound(): void
    {
        $service = $this->service();
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 5), 3 => new Point2D(5, 6)];
        $to = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, -6), 3 => new Point2D(5, -5)];
        $edges = [[0, 1], [2, 3]];

        self::assertSame(0, self::countCrossings(self::toConnections($edges), $from), 'первый кадр чистый');
        self::assertSame(0, self::countCrossings(self::toConnections($edges), $to), 'последний кадр чистый');
        self::assertFalse($service->isTransitionPlanar($from, $to, $edges), 'а переход между ними — нет');
    }

    public function testMovingApartStaysPlanar(): void
    {
        $service = $this->service();
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 5), 3 => new Point2D(5, 6)];
        $to = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 20), 3 => new Point2D(5, 25)];

        self::assertTrue($service->isTransitionPlanar($from, $to, [[0, 1], [2, 3]]));
    }

    public function testSharedVertexIsNotACrossing(): void
    {
        $service = $this->service();
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 5)];
        $to = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, -5)];

        self::assertTrue($service->isTransitionPlanar($from, $to, [[0, 1], [1, 2]]));
    }

    public function testSafeRatioShortensAnUnsafeStep(): void
    {
        $service = $this->service();
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 5), 3 => new Point2D(5, 6)];
        $to = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, -6), 3 => new Point2D(5, -5)];
        $edges = [[0, 1], [2, 3]];

        $ratio = $service->getSafeRatio($from, $to, $edges);

        self::assertGreaterThan(0.0, $ratio);
        self::assertLessThan(1.0, $ratio);
        self::assertTrue($service->isTransitionPlanar($from, $service->interpolate($from, $to, $ratio), $edges));
    }

    public function testSafeRatioKeepsWholeStepWhenItIsSafe(): void
    {
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 0)];
        $to = [0 => new Point2D(0, 1), 1 => new Point2D(10, 1)];

        self::assertSame(1.0, $this->service()->getSafeRatio($from, $to, [[0, 1]]));
    }

    public function testInterpolateMovesEveryVertexItsShare(): void
    {
        $from = [0 => new Point2D(0, 0), 1 => new Point2D(10, 20)];
        $to = [0 => new Point2D(4, 4), 1 => new Point2D(10, 0)];

        $middle = $this->service()->interpolate($from, $to, 0.25);

        self::assertEqualsWithDelta(1.0, $middle[0]->x, 1.0e-9);
        self::assertEqualsWithDelta(1.0, $middle[0]->y, 1.0e-9);
        self::assertEqualsWithDelta(10.0, $middle[1]->x, 1.0e-9);
        self::assertEqualsWithDelta(15.0, $middle[1]->y, 1.0e-9);
    }

    public function testThinKeepsFirstAndLastFrames(): void
    {
        $frames = [];

        for ($i = 0; $i < 100; $i++) {
            $frames[] = [0 => new Point2D($i, 0), 1 => new Point2D($i, 10)];
        }

        $thinned = $this->service()->thin($frames, [[0, 1]], 10);

        self::assertLessThanOrEqual(10, count($thinned));
        self::assertEquals($frames[0], $thinned[0]);
        self::assertEquals($frames[99], $thinned[count($thinned) - 1]);
    }

    public function testThinKeepsFramesThatCannotBeDropped(): void
    {
        // Отрезок 2-3 проходит сквозь 0-1 и возвращается: выбросить середину нельзя.
        $edges = [[0, 1], [2, 3]];
        $frames = [
            [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, 5), 3 => new Point2D(5, 6)],
            [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(20, 5), 3 => new Point2D(20, 6)],
            [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(20, -6), 3 => new Point2D(20, -5)],
            [0 => new Point2D(0, 0), 1 => new Point2D(10, 0), 2 => new Point2D(5, -6), 3 => new Point2D(5, -5)],
        ];

        $thinned = $this->service()->thin($frames, $edges, 2);

        self::assertGreaterThan(2, count($thinned), 'Безопасность важнее заданного числа кадров.');
        self::assertTrue($this->everyTransitionIsPlanar($thinned, $edges));
    }

    /**
     * Сквозная гарантия: всё, что попадает в картинку, проигрывается
     * без единого пересечения.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEveryShownTransitionIsPlanar(array $connections): void
    {
        $service = $this->service();
        $frames = $this->getPlanarService()->connectionsToFrames($connections, new SliceAggregate(new ZeroSlice()));
        $edges = $service->getEdges($connections);

        // Первые два кадра — распутывание клубка, там пересечения и должны быть.
        $shown = array_slice($service->thin($frames, $edges, 60), 2);

        self::assertTrue(
            $this->everyTransitionIsPlanar($shown, $edges),
            'Между кадрами укладка теряет планарность.',
        );
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'треугольник в треугольнике' => [self::getTriangleInTriangle()],
            'три вложенных треугольника' => [self::getTriangleInTriangleInTriangle()],
            'дерево с циклом' => [self::getSmallTree()],
            'большой граф' => [self::getBig1()],
        ];
    }

    /**
     * @param Point2D[][] $frames
     * @param array<int, array{int, int}> $edges
     */
    private function everyTransitionIsPlanar(array $frames, array $edges): bool
    {
        $service = $this->service();
        $count = count($frames);

        for ($i = 0; $i < $count - 1; $i++) {
            if (! $service->isTransitionPlanar($frames[$i], $frames[$i + 1], $edges)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{int, int}> $edges
     *
     * @return array<int, array<int, true>>
     */
    private static function toConnections(array $edges): array
    {
        $result = [];

        foreach ($edges as [$a, $b]) {
            $result[$a][$b] = true;
            $result[$b][$a] = true;
        }

        return $result;
    }

    private function service(): MotionService
    {
        return new MotionService(new GeometryService());
    }
}
