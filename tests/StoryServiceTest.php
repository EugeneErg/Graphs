<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Services\MotionService;
use EugeneErg\Graphs\Services\StoryService;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Stage;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Рассказ о работе алгоритма: каждый шаг виден, разрезанное разъезжается,
 * общие вершины раздваиваются, а потом всё собирается обратно.
 */
final class StoryServiceTest extends AbstractTestCase
{
    /**
     * Каждый этап алгоритма виден в картинке. Не на всяком графе есть что
     * делать на каждом шаге — связный граф не на что разрезать, — поэтому
     * этапы собираются со всех графов сразу.
     *
     * Порядок полей отдельного кадра не получает: вырезанное поле сразу
     * ложится на то место, с которого его и возьмут, поэтому очередь видна
     * с первого разреза и переставлять ничего не нужно.
     */
    public function testEveryStageIsShownSomewhere(): void
    {
        $shown = [];

        foreach (self::getGraphs() as [$connections]) {
            [$trace, $frames] = $this->trace($connections);

            foreach ((new StoryService())->build($trace, $frames, $connections) as $scene) {
                $shown[$scene->kind?->value ?? ''] = true;
            }
        }

        foreach (StageKind::cases() as $kind) {
            if ($kind === StageKind::Order) {
                continue;
            }

            self::assertArrayHasKey($kind->value, $shown, sprintf('Этап «%s» не показан.', $kind->value));
        }
    }

    /**
     * Шаг, на котором есть что показать, не пропадает: если куски разъехались
     * или поле вырезано, в картинке есть соответствующий кадр.
     */
    public function testStageThatDoesSomethingIsNeverSkipped(): void
    {
        $connections = self::merge(false, self::getSmallTree(), self::getSimpleTriangle());
        [$trace, $frames] = $this->trace($connections);
        $shown = [];

        foreach ((new StoryService())->build($trace, $frames, $connections) as $scene) {
            $shown[$scene->kind?->value ?? ''] = true;
        }

        foreach ($trace->getStages() as $stage) {
            if (self::isIdle($stage)) {
                continue;
            }

            self::assertArrayHasKey(
                $stage->kind->value,
                $shown,
                sprintf('Шаг «%s» потерялся.', $stage->caption),
            );
        }
    }

    /**
     * Шаг, которому на этом графе нечего делать: разрезать не на что,
     * выделять нечего, порядок и так верен.
     */
    private static function isIdle(Stage $stage): bool
    {
        return match ($stage->kind) {
            StageKind::Components, StageKind::Branches => count(array_filter($stage->groups)) < 2,
            StageKind::ArticulationVertexes, StageKind::OuterFace => $stage->highlight === [],
            StageKind::Order => true,
            default => false,
        };
    }

    /**
     * Все этапы обязаны присутствовать: заливка, разбиение на куски,
     * точки сочленения, вырезание ветвей, грани, внешняя грань, сборка,
     * раскладка и расслабление.
     */
    public function testWholeAlgorithmIsTold(): void
    {
        [$trace] = $this->trace(self::getSmallTree());
        $kinds = [];

        foreach ($trace->getStages() as $stage) {
            $kinds[$stage->kind->value] = true;
        }

        foreach (StageKind::cases() as $kind) {
            self::assertArrayHasKey($kind->value, $kinds, sprintf('Не рассказан этап «%s».', $kind->value));
        }
    }

    /**
     * Заливка показывается волнами: с какой вершины начали и что добавилось
     * на каждом шаге.
     */
    public function testFillIsShownWaveByWave(): void
    {
        [$trace] = $this->trace(self::getLine());
        $waves = array_values(array_filter(
            $trace->getStages(),
            static fn ($stage): bool => $stage->kind === StageKind::Fill,
        ));

        self::assertGreaterThanOrEqual(2, count($waves), 'Заливка должна идти хотя бы в две волны.');
        self::assertStringContainsString('начинаем с вершины', $waves[0]->caption);
        self::assertSame([0], $waves[0]->highlight, 'Первая волна — стартовая вершина.');
        self::assertSame([1], $waves[1]->highlight, 'Вторая волна — её сосед.');
    }

    public function testFillThatFoundNothingIsNotShown(): void
    {
        [$trace] = $this->trace(self::getDot());

        foreach ($trace->getStages() as $stage) {
            self::assertNotSame(StageKind::Fill, $stage->kind, 'Заливка одной вершины ничего не нашла.');
        }
    }

    /**
     * Заливка не стирает то, что закрашено раньше: на каждой волне видно
     * и прошлые ветви, и текущую.
     */
    public function testFillKeepsWhatWasPaintedBefore(): void
    {
        [$trace] = $this->trace(self::getSmallTree());
        $painted = null;
        $runs = 0;

        foreach ($trace->getStages() as $stage) {
            // Разбор кусков и разрез на ветви красят по-разному, поэтому
            // отсчёт начинается заново на каждой серии заливок.
            if ($stage->kind !== StageKind::Fill) {
                $painted = null;

                continue;
            }

            $now = count($stage->getVertexGroups());

            if ($painted === null) {
                $runs++;
            } else {
                self::assertGreaterThanOrEqual(
                    $painted,
                    $now,
                    sprintf('На шаге «%s» закрашенного стало меньше.', $stage->caption),
                );
            }

            $painted = $now;
        }

        self::assertGreaterThanOrEqual(2, $runs, 'Заливки идут и по кускам, и по ветвям.');
    }

    /**
     * Нарезка на поля видна по шагам: каждое вырезанное поле — свой шаг.
     */
    public function testFieldsAreCutOneByOne(): void
    {
        [$trace] = $this->trace(self::getTriangleInTriangle());
        $fields = array_values(array_filter(
            $trace->getStages(),
            static fn ($stage): bool => $stage->kind === StageKind::Field,
        ));

        self::assertGreaterThanOrEqual(2, count($fields));

        foreach ($fields as $field) {
            self::assertGreaterThanOrEqual(2, count($field->highlight), 'Поле — это путь, а не точка.');
        }
    }

    /**
     * Построение идёт по полю за раз: сначала внешняя грань, потом каждое поле
     * добавляет свои вершины, и назад ничего не пропадает.
     */
    public function testLayoutIsBuiltFieldByField(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getBig1());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $counts = [];
        $total = null;

        foreach ($scenes as $scene) {
            if ($scene->kind !== StageKind::Build) {
                continue;
            }

            // Приглушённые ждут снаружи, но из кадра не пропадают.
            $placed = [];
            $all = [];

            foreach (array_keys($scene->vertexes) as $key) {
                $all[Scene::vertexOf($key)] = true;

                if (! isset($scene->faded[$key])) {
                    $placed[Scene::vertexOf($key)] = true;
                }
            }

            $counts[] = count($placed);
            $total ??= count($all);

            self::assertSame($total, count($all), 'Вершины не должны исчезать из кадра.');
        }

        self::assertGreaterThanOrEqual(3, count($counts), 'Построение должно идти в несколько шагов.');
        self::assertSame(count($frames[1]), $counts[count($counts) - 1], 'В конце построения уложены все вершины.');
        self::assertLessThan($counts[count($counts) - 1], $counts[0], 'Начинаем не со всего графа сразу.');

        for ($i = 1; $i < count($counts); $i++) {
            self::assertGreaterThanOrEqual($counts[$i - 1], $counts[$i], 'Уложенное не должно отыгрываться назад.');
        }
    }

    /**
     * Ребро лежит между двумя полями и режется дважды. Пока разрез только
     * один, ребро помечено: видно, с чем ещё предстоит работа.
     */
    public function testHalfCutEdgeIsMarkedUntilSecondCut(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $marked = [];

        foreach ($scenes as $number => $scene) {
            $marked[$number] = count(array_filter(
                array_keys($scene->groups),
                static fn (string $key): bool => str_contains($key, '-'),
            ));
        }

        // Цвет надреза появляется в момент разреза, а не когда отрезанное
        // доехало до места: выделили поле — и сразу видно, что надрезано.
        foreach ($scenes as $number => $scene) {
            if ($scene->kind === StageKind::Field && $scene->highlight !== [] && isset($scenes[$number + 1])) {
                self::assertSame(
                    $marked[$number + 1],
                    $marked[$number],
                    sprintf('В кадре %d надрез ещё не показан, хотя резать уже начали.', $number),
                );
            }
        }

        self::assertSame(0, $marked[0], 'В целом графе ничего ещё не надрезано.');
        self::assertGreaterThan(0, max($marked), 'После первого разреза надрезанное должно быть видно.');
        self::assertSame(
            0,
            $marked[count($scenes) - 1],
            'В готовой укладке надрезанных рёбер не остаётся: каждое разрезано дважды.',
        );
    }

    /**
     * Перед расслаблением нет паузы: собранная укладка не показывается
     * второй раз, а сразу начинает расходиться.
     */
    public function testRelaxationStartsRightAfterBuilding(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getBig1());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $relax = array_values(array_filter(
            $scenes,
            static fn (Scene $scene): bool => $scene->kind === StageKind::Relax,
        ));

        self::assertGreaterThanOrEqual(2, count($relax));
        self::assertLessThan(
            $relax[count($relax) - 1]->weight / 4,
            $relax[0]->weight,
            'Первый кадр расслабления повторяет собранную укладку — держать его незачем.',
        );
    }

    /**
     * Расслабление начинается с построенной раскладки, а не с клубка,
     * поэтому все его переходы плоские.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testRelaxationNeverCrosses(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);
        $motion = new MotionService();
        $edges = $motion->getEdges($connections);
        $scenes = array_values(array_filter(
            (new StoryService())->build($trace, $frames, $connections),
            static fn (Scene $scene): bool => $scene->kind === StageKind::Relax,
        ));

        // На маленьком графе укладке Татта расходиться уже некуда: кадр
        // всего один, и проверять в нём нечего.
        self::assertGreaterThanOrEqual(1, count($scenes), 'Расслабление должно быть в рассказе.');

        for ($i = 0; $i < count($scenes) - 1; $i++) {
            self::assertTrue(
                $motion->isTransitionPlanar(
                    $this->pointsOf($scenes[$i]),
                    $this->pointsOf($scenes[$i + 1]),
                    $edges,
                ),
                sprintf('Переход %d расслабления теряет планарность.', $i),
            );
        }
    }

    public function testDisconnectedComponentsMoveApart(): void
    {
        $connections = self::merge(false, self::getSimpleTriangle(), self::getRectangle());
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findScene($scenes, StageKind::Components);

        $first = $this->boundsOf($scene, [0, 1, 2]);
        $second = $this->boundsOf($scene, [3, 4, 5, 6]);

        self::assertTrue(
            $first[1] < $second[0] || $second[1] < $first[0]
                || $first[3] < $second[2] || $second[3] < $first[2],
            'Несвязные куски должны стоять врозь, а не друг на друге.',
        );
    }

    /**
     * Точка сочленения принадлежит нескольким ветвям, поэтому при разрезании
     * она раздваивается: в кадре появляются её копии.
     */
    public function testArticulationVertexIsDuplicatedWhenBranchesAreCut(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getSmallTree());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $spots = $this->spotsOf($this->findScene($scenes, StageKind::Branches));

        self::assertSame(2, $spots[4] ?? 0, 'Вершина 4 лежит в двух ветвях.');
        self::assertSame(1, $spots[0] ?? 0, 'Обычная вершина не раздваивается.');
    }

    /**
     * Сборка возвращает копии на место: в готовой укладке вершина снова одна,
     * и никаких раздвоенных обломков рядом не остаётся.
     */
    public function testBuildBringsCopiesBackOntoTheirVertex(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getSmallTree());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $spots = $this->spotsOf($scenes[count($scenes) - 1]);

        self::assertCount(count($frames[0]), $spots, 'В готовой укладке должны быть все вершины.');

        foreach ($spots as $vertex => $count) {
            self::assertSame(1, $count, sprintf('Вершина %d осталась раздвоенной.', $vertex));
        }
    }

    /**
     * Поле вырезается физически: кусок отходит целиком, а вершина, которая
     * ещё нужна соседям, раздваивается — как вырезанный кусок пирога.
     */
    public function testCutFieldTakesItsOwnCopyOfSharedVertexes(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findLastScene($scenes, StageKind::Field);
        $spots = $this->spotsOf($scene);

        self::assertGreaterThan(
            count($connections),
            array_sum($spots),
            'Общие вершины должны разъехаться, иначе кусок не отрезан.',
        );

        // Ребро на границе двух полей тоже раздваивается: каждому полю своё.
        $edges = [];

        foreach (array_keys($scene->edges) as $key) {
            $edges[explode(':', $key)[0]] = ($edges[explode(':', $key)[0]] ?? 0) + 1;
        }

        self::assertContains(2, $edges, 'Ребро между двумя полями должно достаться обоим.');
    }

    /**
     * Куски не наезжают друг на друга: у каждого своё место на столе.
     */
    public function testCutPiecesDoNotOverlap(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getTriangleInTriangle());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findLastScene($scenes, StageKind::Field);
        $seen = [];

        foreach ($scene->vertexes as $key => $point) {
            $place = self::pointKey($point);
            // В одной точке могут стоять только копии одной вершины: пока
            // кусок не отрезан, его копии лежат поверх своих оригиналов.
            self::assertSame(
                Scene::vertexOf($seen[$place] ?? $key),
                Scene::vertexOf($key),
                sprintf('Экземпляры %s и %s стоят в одной точке.', $seen[$place] ?? '', $key),
            );

            $seen[$place] = $key;
        }
    }

    /**
     * Ничто не возникает из воздуха и никуда не девается: набор экземпляров
     * один и тот же во всех кадрах. Отрезанный кусок отделяется от графа
     * на глазах, потому что до разреза он лежит поверх него.
     *
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testNothingAppearsOutOfThinAir(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $vertexes = array_keys($scenes[0]->vertexes);
        $edges = array_keys($scenes[0]->edges);
        sort($vertexes);
        sort($edges);

        foreach ($scenes as $number => $scene) {
            $sceneVertexes = array_keys($scene->vertexes);
            $sceneEdges = array_keys($scene->edges);
            sort($sceneVertexes);
            sort($sceneEdges);

            self::assertSame($vertexes, $sceneVertexes, sprintf('В кадре %d состав вершин другой.', $number));
            self::assertSame($edges, $sceneEdges, sprintf('В кадре %d состав рёбер другой.', $number));
        }
    }

    /**
     * Едет только то, что режут: место куска, раз назначенное, больше
     * не меняется. Иначе при каждом разрезе едут все, и уследить невозможно.
     */
    public function testCutPieceKeepsItsPlaceForever(): void
    {
        [$trace, , $connections] = $this->trace(self::getBig1());
        $plan = (new StoryService())->getCutPlan($trace, $connections);
        $places = [];

        for ($number = 0; $number <= $plan->getLastState(); $number++) {
            foreach ($plan->getState($number)['places'] as $id => $place) {
                self::assertSame(
                    $places[$id] ?? $place,
                    $place,
                    sprintf('Кусок %d переехал на другое место.', $id),
                );
                $places[$id] = $place;
            }
        }

        self::assertNotSame([], $places, 'Хоть что-то за нарезку отрезать должны.');
    }

    /**
     * У каждого несвязного куска свой стол: нарезка одного не лезет на другой,
     * и собираются они тоже врозь.
     *
     * @param true[][] $connections
     * @param int[] $first
     * @param int[] $second
     */
    #[DataProvider('getDisconnectedGraphs')]
    public function testDisconnectedGraphsDoNotShareTheTable(array $connections, array $first, array $second): void
    {
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);

        foreach ($scenes as $number => $scene) {
            if ($scene->kind === StageKind::Graph || $scene->kind === StageKind::Fill) {
                // Пока куски не разъехались, они и правда в одном клубке.
                continue;
            }

            [$leftA, $rightA] = $this->boundsOf($scene, $first);
            [$leftB, $rightB] = $this->boundsOf($scene, $second);

            self::assertTrue(
                $rightA < $leftB || $rightB < $leftA,
                sprintf('В кадре %d куски налезли друг на друга.', $number),
            );
        }
    }

    /**
     * Рёбра разных несвязных кусков не пересекаются ни в одном кадре — в том
     * числе в клубке, где куски ещё лежат вперемешку.
     *
     * @param true[][] $connections
     * @param int[] $first
     * @param int[] $second
     */
    #[DataProvider('getDisconnectedGraphs')]
    public function testDisconnectedGraphsNeverCross(array $connections, array $first, array $second): void
    {
        [$trace, $frames] = $this->trace($connections);
        $geometry = $this->getGeometryService();
        $parts = array_replace(array_fill_keys($first, 0), array_fill_keys($second, 1));

        foreach ((new StoryService())->build($trace, $frames, $connections) as $number => $scene) {
            $edges = [];

            foreach ($scene->edges as $key => [$from, $to]) {
                $edges[] = [$parts[Scene::vertexOf(explode('-', $key)[0])] ?? 0, $from, $to];
            }

            for ($i = 0; $i < count($edges); $i++) {
                for ($j = $i + 1; $j < count($edges); $j++) {
                    if ($edges[$i][0] === $edges[$j][0]) {
                        continue;
                    }

                    self::assertFalse(
                        $geometry->segmentsIntersect($edges[$i][1], $edges[$i][2], $edges[$j][1], $edges[$j][2]),
                        sprintf('В кадре %d рёбра разных кусков пересеклись.', $number),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array{true[][], int[], int[]}>
     */
    public static function getDisconnectedGraphs(): array
    {
        return [
            'дерево с циклом и треугольник' => [
                self::merge(false, self::getSmallTree(), self::getSimpleTriangle()),
                range(0, 7),
                range(8, 10),
            ],
            'большой с перешейками' => [self::getBig2(), range(0, 16), range(17, 23)],
        ];
    }

    /**
     * Выделение держится до самого действия: точки сочленения обведены
     * с момента, как их нашли, и до момента, когда по ним разрезали.
     */
    public function testMarkStaysUntilTheCutItWasMadeFor(): void
    {
        [$trace, $frames, $connections] = $this->trace(self::getSmallTree());
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $from = null;
        $to = null;

        foreach ($scenes as $number => $scene) {
            if ($scene->kind === StageKind::ArticulationVertexes) {
                $from ??= $number;
            }

            if ($scene->kind === StageKind::Branches) {
                $to = $number;
            }
        }

        self::assertNotNull($from);
        self::assertNotNull($to);
        self::assertGreaterThan($from, $to, 'Между выделением и разрезом должен быть поиск ветвей.');

        for ($i = $from; $i <= $to; $i++) {
            $marked = [];

            foreach (array_keys($scenes[$i]->highlight) as $key) {
                $marked[Scene::vertexOf($key)] = true;
            }

            foreach ([4, 5, 6] as $vertex) {
                self::assertArrayHasKey(
                    $vertex,
                    $marked,
                    sprintf('В кадре %d потерялось выделение точки сочленения %d.', $i, $vertex),
                );
            }
        }
    }

    /**
     * Разрез накапливается: когда режут один кусок, остальные остаются целыми
     * и никуда не деваются.
     */
    public function testCuttingOneComponentKeepsTheOtherWhole(): void
    {
        $connections = self::merge(false, self::getSmallTree(), self::getSimpleTriangle());
        [$trace, $frames] = $this->trace($connections);
        $scenes = (new StoryService())->build($trace, $frames, $connections);
        $scene = $this->findScene($scenes, StageKind::Branches);
        $vertexes = [];

        foreach (array_keys($scene->vertexes) as $key) {
            $vertexes[Scene::vertexOf($key)] = true;
        }

        foreach (array_keys($connections) as $vertex) {
            self::assertArrayHasKey($vertex, $vertexes, sprintf('Вершина %d пропала из кадра.', $vertex));
        }
    }

    /**
     * @param true[][] $connections
     */
    #[DataProvider('getGraphs')]
    public function testEverySceneKeepsEdgesAttachedToVertexes(array $connections): void
    {
        [$trace, $frames] = $this->trace($connections);

        foreach ((new StoryService())->build($trace, $frames, $connections) as $number => $scene) {
            $points = [];

            foreach ($scene->vertexes as $point) {
                $points[self::pointKey($point)] = true;
            }

            foreach ($scene->edges as $key => [$from, $to]) {
                self::assertArrayHasKey(
                    self::pointKey($from),
                    $points,
                    sprintf('Ребро %s в кадре %d висит в воздухе.', $key, $number),
                );
                self::assertArrayHasKey(self::pointKey($to), $points);
            }
        }
    }

    /**
     * @return array<string, array{true[][]}>
     */
    public static function getGraphs(): array
    {
        return [
            'треугольник' => [self::getSimpleTriangle()],
            'дерево с циклом' => [self::getSmallTree()],
            'два куска' => [self::merge(false, self::getSimpleTriangle(), self::getRectangle())],
            'большой граф' => [self::getBig1()],
            'большой с перешейками' => [self::getBig2()],
        ];
    }

    /**
     * @param true[][] $connections
     *
     * @return array{Trace, Point2D[][], true[][]}
     */
    private function trace(array $connections): array
    {
        $trace = new Trace();
        $frames = $this->getPlanarService()->connectionsToFrames(
            $connections,
            new SliceAggregate(new ZeroSlice()),
            100.0,
            $trace,
        );

        return [$trace, $frames, $connections];
    }

    /**
     * @param Scene[] $scenes
     */
    private function findScene(array $scenes, StageKind $kind): Scene
    {
        foreach ($scenes as $scene) {
            if ($scene->kind === $kind) {
                return $scene;
            }
        }

        self::fail(sprintf('В рассказе нет кадра для этапа «%s».', $kind->value));
    }

    /**
     * Последний кадр разреза: после него кусок уже начинают собирать обратно.
     *
     * @param Scene[] $scenes
     */
    private function findLastScene(array $scenes, StageKind $kind): Scene
    {
        $result = null;

        foreach ($scenes as $scene) {
            if ($scene->kind === $kind) {
                $result = $scene;
            }
        }

        self::assertNotNull($result, sprintf('В рассказе нет кадра для этапа «%s».', $kind->value));

        return $result;
    }

    /**
     * @param int[] $vertexes
     *
     * @return array{float, float, float, float} слева, справа, сверху, снизу
     */
    private function boundsOf(Scene $scene, array $vertexes): array
    {
        $result = [INF, -INF, INF, -INF];

        foreach ($scene->vertexes as $key => $point) {
            if (in_array(Scene::vertexOf($key), $vertexes, true)) {
                $result = [
                    min($result[0], $point->x),
                    max($result[1], $point->x),
                    min($result[2], $point->y),
                    max($result[3], $point->y),
                ];
            }
        }

        return $result;
    }

    /**
     * Сколько мест в кадре занимает вершина. Экземпляры, лежащие друг на
     * друге, глазу неразличимы, поэтому считаются за одно место.
     *
     * @return array<int, int> вершина => сколько разных мест она занимает
     */
    private function spotsOf(Scene $scene): array
    {
        $spots = [];

        foreach ($scene->vertexes as $key => $point) {
            $spots[Scene::vertexOf($key)][self::pointKey($point)] = true;
        }

        return array_map(count(...), $spots);
    }

    /**
     * @return Point2D[] вершина => её место в кадре
     */
    private function pointsOf(Scene $scene): array
    {
        $result = [];

        foreach ($scene->vertexes as $key => $point) {
            $result[Scene::vertexOf($key)] = $point;
        }

        return $result;
    }

    private static function pointKey(Point2D $point): string
    {
        return round($point->x, 6) . ',' . round($point->y, 6);
    }
}
