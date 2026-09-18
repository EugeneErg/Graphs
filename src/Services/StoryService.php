<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\CutPlan;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Piece;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\Scene;
use EugeneErg\Graphs\ValueObjects\Stage;
use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Превращает журнал шагов в последовательность кадров.
 *
 * Рассказ держится на трёх правилах.
 *
 * Первое: за выделением сразу следует то, ради чего выделяли, — отметили кусок
 * и тут же его отрезали.
 *
 * Второе: резать по-настоящему. Отрезанный кусок уезжает в сторону целиком, а
 * вершины и рёбра на границе разреза раздваиваются, чтобы связи с бывшим
 * соседом не осталось: так же отрезают кусок пирога.
 *
 * Третье: едет только то, что режут. Весь план разреза известен заранее
 * (`CutPlan`), поэтому у куска одно место на столе на всю анимацию, а его
 * экземпляры лежат поверх оригиналов ещё до разреза. Отрезанное отделяется от
 * графа на глазах, а не появляется из воздуха, и соседние куски при этом
 * стоят на месте.
 *
 * Подписей нет: что происходит, должно быть видно по самому происходящему.
 */
final readonly class StoryService
{
    /** Сколько времени держится выделение перед действием. */
    private const float MARK_WEIGHT = 0.7;

    /** Действие: кусок отъезжает, поле встаёт на место. */
    private const float ACT_WEIGHT = 1.0;

    /** Волна заливки короче: их много, и каждая мелкая. */
    private const float WAVE_WEIGHT = 0.5;

    /** Столько времени занимает целиком шаг, на котором вершины едут. */
    private const float MOVING_WEIGHT = 4.0;

    /** Насколько круг мест отодвинут от исходного рисунка. */
    private const float RING_GAP = 1.5;

    /** Надрезанное ребро: одно поле его уже забрало, второе ещё нет. */
    private const int HALF_GROUP = -1;

    /** Первый кадр расслабления повторяет собранную укладку: держать его незачем. */
    private const float BEAT_WEIGHT = 0.15;

    /** Стол, на котором лежит весь граф, пока он не распался на куски. */
    private const int WHOLE_TABLE = 0;

    public function __construct(
        private MotionService $motion = new MotionService(),
        private int $targetFrames = 2,
    ) {
    }

    /**
     * @param Point2D[][] $frames кадры укладки: клубок, начальная раскладка, расслабление
     * @param array<int, array<int, mixed>> $connections
     *
     * @return Scene[]
     */
    public function build(Trace $trace, array $frames, array $connections): array
    {
        $frames = array_values($frames);
        $tangled = $frames[0] ?? [];
        $edges = $this->motion->getEdges($connections);
        $stages = $trace->getStages();
        $whole = Piece::ofEdges($edges);

        if ($stages === [] || $tangled === []) {
            return $this->getMovingScenes(new CutPlan($whole, []), $frames, $edges, 0, count($frames) - 1);
        }

        $plan = $this->getCutPlan($trace, $connections);
        $spacing = $this->getSpacing($tangled);
        $order = $this->getBuildOrder($stages);
        $tables = $this->getTables($plan, $tangled, $frames[count($frames) - 1], $spacing);
        // Каждый кусок собирается у себя на столе, поэтому готовая укладка
        // переносится туда же.
        $building = $this->getShifted($frames[1] ?? $tangled, $plan, $tables);
        $result = [];
        $marked = [];
        $step = 0;

        foreach ($stages as $number => $stage) {
            $scenes = $this->getStageScenes($plan, $number, $stage, $order, $step, $tangled, $tables, $spacing, $building);

            foreach ($scenes as $scene) {
                $result[] = $marked === [] ? $scene : $this->withMark($scene, $marked);
            }

            if ($stage->kind === StageKind::Build) {
                $step++;
            }

            // Кольцо на точках сочленения держится до самого разреза: сначала
            // видно, где резать, потом — как по этим местам и разрезали.
            $marked = match ($stage->kind) {
                StageKind::ArticulationVertexes => $stage->highlight,
                StageKind::Branches => [],
                default => $marked,
            };
        }

        $moving = array_map(
            fn (array $frame): array => $this->getShifted($frame, $plan, $tables),
            $frames,
        );

        foreach ($this->getMovingScenes($plan, $moving, $edges, min(1, count($frames) - 1), count($frames) - 1) as $scene) {
            $result[] = $scene;
        }

        return $result;
    }

    /**
     * Весь разрез, посчитанный до первого кадра.
     *
     * @param array<int, array<int, mixed>> $connections
     */
    public function getCutPlan(Trace $trace, array $connections): CutPlan
    {
        $edges = $this->motion->getEdges($connections);
        $stages = $trace->getStages();
        $result = new CutPlan(Piece::ofEdges($edges), $this->getCutCount($edges, $stages));
        $order = $this->getBuildOrder($stages);

        foreach ($stages as $stage) {
            match ($stage->kind) {
                StageKind::Components => $result->split($stage->groups, true),
                StageKind::Branches => $result->split($stage->groups),
                StageKind::Field => $result->cutOut(Piece::ofWalk($stage->highlight)),
                default => null,
            };
            $result->snapshot();
        }

        // Места раздаются в порядке сборки: очередь видна сразу, и уже
        // разложенные куски не переставляются на глазах.
        $result->orderSlots($order);

        return $result;
    }

    /**
     * Кадры одного шага.
     *
     * Состояние с номером шага — это то, что стало после него; предыдущее —
     * то, что было до. Поэтому выделение показывается на «до», а действие —
     * на «после», и шаг, на котором ничего не изменилось, действия не получает.
     *
     * @param array<int, int> $order вершина => каким по счёту шагом её уложат
     * @param Point2D[] $tangled
     * @param array<int, array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D}> $tables
     * @param Point2D[] $building
     *
     * @return Scene[]
     */
    private function getStageScenes(
        CutPlan $plan,
        int $number,
        Stage $stage,
        array $order,
        int $step,
        array $tangled,
        array $tables,
        float $spacing,
        array $building,
    ): array {
        $before = $this->getPlaces($plan, $number, $tangled, $tables, $spacing);
        $after = $this->getPlaces($plan, $number + 1, $tangled, $tables, $spacing);
        // Надрез виден в тот момент, когда режут, а не когда отрезанное
        // доехало до своего места: это же и есть разрез.
        $isHalf = $this->getHalfGroups($plan, $number + 1, $after);
        $wasHalf = $this->getHalfGroups($plan, $number + 1, $before);
        $isHalf += $this->getFieldGroups($plan, $number + 1, $after);
        $wasHalf += $this->getFieldGroups($plan, $number + 1, $before);
        $act = $plan->isChanged($number + 1) || $stage->kind === StageKind::Faces
            ? [$this->getScene($after, $stage->kind, $isHalf, [], [], self::ACT_WEIGHT)]
            : [];

        return match ($stage->kind) {
            StageKind::Graph => [$this->getScene($after, $stage->kind, $isHalf, [], [], self::ACT_WEIGHT)],
            StageKind::Fill => [$this->getPaintedScene($after, $stage, $isHalf)],
            StageKind::ArticulationVertexes => $stage->highlight === []
                ? []
                : [$this->getMarkedScene($before, $this->getKeysOf($before, $stage->highlight), $stage->kind, $wasHalf)],
            StageKind::Field, StageKind::OuterFace => array_merge(
                $stage->highlight === []
                    ? []
                    : [$this->getMarkedScene($before, $this->getActedKeys($plan, $number + 1, $before, $stage), $stage->kind, $wasHalf)],
                $act,
            ),
            StageKind::Components, StageKind::Branches, StageKind::Order, StageKind::Faces => $act,
            StageKind::Build => [$this->getBuildScene($plan, $after, $stage, $order, $step, $building, $isHalf)],
            default => [],
        };
    }

    /**
     * Где что лежит в этом состоянии.
     *
     * Живые куски стоят каждый на своём месте, а ещё не отрезанные — поверх
     * того куска, из которого их вырежут. Уже разрезанные не рисуются: их
     * экземпляры достались частям.
     *
     * @param Point2D[] $tangled
     * @param array<int, array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D}> $tables
     *
     * @return array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>}
     */
    private function getPlaces(CutPlan $plan, int $number, array $tangled, array $tables, float $spacing): array
    {
        $state = $plan->getState($number);
        $live = array_flip($state['live']);
        $positions = [];
        $pieces = [];
        $hosts = [];

        foreach ($state['live'] as $id) {
            $cutout = $plan->getCutout($id);
            $place = $state['places'][$id];
            $table = $tables[$plan->getTable($id)] ?? null;
            $points = $table === null || $place === CutPlan::HOME
                ? $this->getHomePoints($cutout->piece, $tangled, $table)
                : $this->getRingPoints($cutout->piece, $table['slots'][$place] ?? new Point2D(), $spacing);
            $hosts[$id] = $id;

            foreach ($points as $vertex => $point) {
                $positions[$cutout->vertexes[$vertex]] = $point;
                $pieces[$id][$vertex] = $cutout->vertexes[$vertex];
            }
        }

        foreach ($plan->getCutouts() as $id => $cutout) {
            if (isset($live[$id]) || $plan->getBirth($id) <= $number) {
                continue;
            }

            $host = $plan->getHost($id, $live);

            if ($host === null) {
                continue;
            }

            $hosts[$id] = $host;

            foreach ($cutout->piece->vertexes as $vertex) {
                $key = $plan->getCutout($host)->vertexes[$vertex] ?? null;

                if ($key === null || ! isset($positions[$key])) {
                    continue;
                }

                $positions[$cutout->vertexes[$vertex]] = $positions[$key];
                $pieces[$id][$vertex] = $cutout->vertexes[$vertex];
            }
        }

        $edges = [];

        foreach ($pieces as $id => $vertexes) {
            $cutout = $plan->getCutout($id);

            foreach ($cutout->piece->edges as [$vertexA, $vertexB]) {
                if (isset($vertexes[$vertexA], $vertexes[$vertexB])) {
                    $edges[$cutout->edges[Scene::edgeName($vertexA, $vertexB)]] = [$vertexes[$vertexA], $vertexes[$vertexB]];
                }
            }
        }

        return ['positions' => $positions, 'edges' => $edges, 'pieces' => $pieces, 'hosts' => $hosts];
    }

    /**
     * Кусок у себя дома: вершины стоят по кругу своего стола, равномерно и
     * в том же порядке, в каком стояли в клубке.
     *
     * Равномерно — потому что кусок по ходу нарезки худеет: если оставлять
     * вершины на прежних местах, от графа остаются редкие ошмётки с дырами.
     * А порядок сохраняется, чтобы вершины не перемешивались.
     *
     * @param Point2D[] $tangled
     * @param ?array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D} $table
     *
     * @return Point2D[]
     */
    private function getHomePoints(Piece $piece, array $tangled, ?array $table): array
    {
        if ($table === null) {
            return $this->getPoints($piece->vertexes, $tangled);
        }

        $center = $this->getCenter($tangled);
        $angles = [];

        foreach ($piece->vertexes as $vertex) {
            $point = $tangled[$vertex] ?? $center;
            $angles[$vertex] = atan2($point->y - $center->y, $point->x - $center->x);
        }

        asort($angles);
        $count = count($angles);
        $start = $count === 0 ? .0 : (float) reset($angles);
        $result = [];
        $number = 0;

        foreach (array_keys($angles) as $vertex) {
            $angle = $start + 2 * M_PI * $number / max($count, 1);
            $result[$vertex] = $count === 1
                ? $table['center']
                : new Point2D(
                    $table['center']->x + $table['radius'] * cos($angle),
                    $table['center']->y + $table['radius'] * sin($angle),
                );
            $number++;
        }

        return $result;
    }

    /**
     * Переносит укладку на стол своего куска.
     *
     * @param Point2D[] $frame
     * @param array<int, array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D}> $tables
     *
     * @return Point2D[]
     */
    private function getShifted(array $frame, CutPlan $plan, array $tables): array
    {
        $shifts = [];

        foreach ($tables as $table => $item) {
            foreach ($plan->getTableVertexes($table) as $vertex) {
                $shifts[$vertex] = $item['shift'];
            }
        }

        $result = [];

        foreach ($frame as $vertex => $point) {
            $shift = $shifts[$vertex] ?? null;
            $result[$vertex] = $shift === null
                ? $point
                : new Point2D($point->x + $shift->x, $point->y + $shift->y);
        }

        return $result;
    }

    /**
     * Отрезанный кусок на своём месте: вершины по кругу в порядке обхода,
     * поэтому поле выглядит многоугольником.
     *
     * @return Point2D[]
     */
    private function getRingPoints(Piece $piece, Point2D $center, float $spacing): array
    {
        $vertexes = array_values($piece->vertexes);
        $count = count($vertexes);
        $radius = $this->getPieceRadius($count, $spacing);
        $result = [];

        foreach ($vertexes as $position => $vertex) {
            $result[$vertex] = $count === 1
                ? $center
                : new Point2D(
                    $center->x + $radius * sin(2 * M_PI * $position / $count),
                    $center->y - $radius * cos(2 * M_PI * $position / $count),
                );
        }

        return $result;
    }

    /**
     * Столы: у каждого несвязного куска свой.
     *
     * На столе кусок лежит в середине, а вырезанное из него раскладывается
     * по кругу вокруг. Столы стоят в ряд и не задевают друг друга, поэтому
     * два несвязных графа не мешают друг другу ни при нарезке, ни при сборке:
     * каждый собирается у себя.
     *
     * Размер стола считается по готовой укладке, а не по той, что была до
     * расслабления: расслабленная шире, и если мерить по ней задним числом,
     * куски вылезут на соседний стол.
     *
     * @param Point2D[] $tangled
     * @param Point2D[] $final готовая укладка
     *
     * @return array<int, array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D}>
     */
    private function getTables(CutPlan $plan, array $tangled, array $final, float $spacing): array
    {
        $sizes = [];

        foreach ($plan->getTables() as $table) {
            $vertexes = $plan->getTableVertexes($table);
            $built = $this->getCenter($this->getPoints($vertexes, $final));
            // Дома вершины стоят по кругу — ровно так, чтобы держать то же
            // расстояние, что и в исходном клубке.
            $home = $this->getPieceRadius(count($vertexes), $spacing);
            $piece = $spacing;

            foreach ($plan->getSlotted($table) as $cutout) {
                $piece = max($piece, $this->getPieceRadius(count($cutout->piece->vertexes), $spacing));
            }

            $count = $plan->getSlotCount($table);
            // Круг мест должен обходить и домашний круг, и собранную укладку,
            // и быть достаточно большим, чтобы соседние куски на нём
            // не задевали друг друга.
            $outside = max($home, $this->getRadius($built, $this->getPoints($vertexes, $final)));
            $ring = $count === 0
                ? .0
                : max($outside + $piece * self::RING_GAP, 1.2 * $count * $piece / M_PI + $piece);
            $sizes[$table] = [
                'built' => $built,
                'home' => $home,
                'ring' => $ring,
                'count' => $count,
                'extent' => max($ring + $piece, $outside),
            ];
        }

        // Стол, с которого всё началось, стоит там же, где лежал клубок.
        // Если граф распался на несвязные куски, этот стол пустеет — каждый
        // кусок уезжает на свой, и в ряд встают уже они.
        $center = $this->getCenter($tangled);
        $result = $this->getPlacedTables(
            count($sizes) > 1 ? array_diff_key($sizes, [self::WHOLE_TABLE => true]) : $sizes,
            $center,
            $spacing,
        );

        if (! isset($result[self::WHOLE_TABLE])) {
            $result[self::WHOLE_TABLE] = [
                'center' => $center,
                'radius' => $sizes[self::WHOLE_TABLE]['home'],
                'slots' => [],
                'shift' => new Point2D(
                    $center->x - $sizes[self::WHOLE_TABLE]['built']->x,
                    $center->y - $sizes[self::WHOLE_TABLE]['built']->y,
                ),
            ];
        }

        return $result;
    }

    /**
     * Ставит столы в ряд, слева направо в том же порядке, в каком куски стоят
     * в готовой укладке, и считает для каждого его места.
     *
     * @param array<int, array{built: Point2D, home: float, ring: float, count: int, extent: float}> $sizes
     *
     * @return array<int, array{center: Point2D, radius: float, slots: Point2D[], shift: Point2D}>
     */
    private function getPlacedTables(array $sizes, Point2D $center, float $spacing): array
    {
        uasort($sizes, static fn (array $a, array $b): int => $a['built']->x <=> $b['built']->x);
        $width = .0;

        foreach ($sizes as $size) {
            $width += $size['extent'] * 2 + $spacing;
        }

        $offset = $center->x - ($width - $spacing) / 2;
        $result = [];

        foreach ($sizes as $table => $size) {
            $point = new Point2D($offset + $size['extent'], $center->y);
            $offset += $size['extent'] * 2 + $spacing;
            $slots = [];

            for ($number = 0; $number < $size['count']; $number++) {
                $angle = 2 * M_PI * $number / $size['count'];
                $slots[] = new Point2D(
                    $point->x + $size['ring'] * sin($angle),
                    $point->y - $size['ring'] * cos($angle),
                );
            }

            $result[$table] = [
                'center' => $point,
                'radius' => $size['home'],
                'slots' => $slots,
                'shift' => new Point2D($point->x - $size['built']->x, $point->y - $size['built']->y),
            ];
        }

        return $result;
    }

    /**
     * @param int[] $vertexes
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    private function getPoints(array $vertexes, array $coordinates): array
    {
        $result = [];

        foreach ($vertexes as $vertex) {
            if (isset($coordinates[$vertex])) {
                $result[$vertex] = $coordinates[$vertex];
            }
        }

        return $result;
    }

    /**
     * Насколько далеко точки уходят от центра.
     *
     * @param Point2D[] $points
     */
    private function getRadius(Point2D $center, array $points): float
    {
        $result = .0;

        foreach ($points as $point) {
            $result = max($result, sqrt(($point->x - $center->x) ** 2 + ($point->y - $center->y) ** 2));
        }

        return $result;
    }

    /**
     * @param Point2D[] $points
     */
    private function getCenter(array $points): Point2D
    {
        if ($points === []) {
            return new Point2D();
        }

        $left = INF;
        $right = -INF;
        $top = INF;
        $bottom = -INF;

        foreach ($points as $point) {
            $left = min($left, $point->x);
            $right = max($right, $point->x);
            $top = min($top, $point->y);
            $bottom = max($bottom, $point->y);
        }

        return new Point2D(($left + $right) / 2, ($top + $bottom) / 2);
    }

    private function getPieceRadius(int $count, float $spacing): float
    {
        return $count < 2 ? $spacing / 2 : max($spacing, $spacing / (2 * sin(M_PI / $count)));
    }

    /**
     * Заливка: цвет расползается по связям, куски пока целы.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param array<string, int> $groups
     */
    private function getPaintedScene(array $places, Stage $stage, array $groups): Scene
    {

        foreach ($stage->getVertexGroups() as $vertex => $group) {
            foreach ($this->getVertexKeys($places, $vertex) as $key) {
                $groups[$key] = $group;
            }
        }

        return $this->getScene($places, $stage->kind, $groups, [], [], self::WAVE_WEIGHT);
    }

    /**
     * Отрезанные поля лежат раскрашенными: каждое своим цветом, по месту
     * на столе — так соседние поля различимы, и видно, сколько их получилось.
     * Цвет держится всё время, пока поле лежит, и сходит, когда поле
     * возвращается в укладку.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     *
     * @return array<string, int>
     */
    private function getFieldGroups(CutPlan $plan, int $number, array $places): array
    {
        $places['pieces'] = array_intersect_key($places['pieces'], array_flip($plan->getState($number)['live']));
        $result = [];

        foreach ($places['pieces'] as $id => $vertexes) {
            $place = $plan->getState($number)['places'][$id] ?? CutPlan::HOME;

            if ($place === CutPlan::HOME) {
                continue;
            }

            foreach ($vertexes as $key) {
                $result[$key] = $place;
            }

            foreach ($plan->getCutout($id)->piece->edges as [$vertexA, $vertexB]) {
                if (isset($vertexes[$vertexA], $vertexes[$vertexB])) {
                    $result[$plan->getCutout($id)->edges[Scene::edgeName($vertexA, $vertexB)]] = $place;
                }
            }
        }

        return $result;
    }

    /**
     * Выделение: отмечаем то, с чем сейчас будем работать.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param array<string, true> $marked
     * @param array<string, int> $groups
     */
    private function getMarkedScene(array $places, array $marked, StageKind $kind, array $groups): Scene
    {
        return $this->getScene($places, $kind, $groups, $marked, [], self::MARK_WEIGHT);
    }

    /**
     * Надрезанные рёбра: те, которые уже отошли одному полю и ждут второго
     * разреза. Ребро всегда режут ровно дважды, и по цвету видно, сколько
     * работы с ним осталось.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     *
     * @return array<string, int>
     */
    private function getHalfGroups(CutPlan $plan, int $number, array $places): array
    {
        $result = [];

        foreach (array_keys($plan->getState($number)['half']) as $key) {
            if (isset($places['edges'][$key])) {
                $result[$key] = self::HALF_GROUP;
            }
        }

        return $result;
    }

    /**
     * Экземпляры того куска, который сейчас и отрежут: обводим именно его,
     * а не все копии этих вершин по всему столу.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     *
     * @return array<string, true>
     */
    private function getActedKeys(CutPlan $plan, int $number, array $places, Stage $stage): array
    {
        $acted = $plan->getState($number)['acted'];

        if ($acted === null || ! isset($places['pieces'][$acted])) {
            return $this->getKeysOf($places, $stage->highlight);
        }

        $result = [];

        foreach ($places['pieces'][$acted] as $key) {
            $result[$key] = true;
        }

        return $result;
    }

    /**
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param int[] $vertexes
     *
     * @return array<string, true>
     */
    private function getKeysOf(array $places, array $vertexes): array
    {
        $result = [];

        foreach ($vertexes as $vertex) {
            foreach ($this->getVertexKeys($places, $vertex) as $key) {
                $result[$key] = true;
            }
        }

        return $result;
    }

    /**
     * Сборка: очередной кусок уезжает на своё место в укладке целиком,
     * остальные ждут на столе приглушёнными.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param array<int, int> $order
     * @param Point2D[] $building
     * @param array<string, int> $groups
     */
    private function getBuildScene(CutPlan $plan, array $places, Stage $stage, array $order, int $step, array $building, array $groups): Scene
    {
        $positions = $places['positions'];
        $faded = [];

        foreach ($places['pieces'] as $id => $vertexes) {
            $built = $plan->getRank($places['hosts'][$id] ?? $id, $order) <= $step;

            foreach ($vertexes as $vertex => $key) {
                if ($built && isset($building[$vertex])) {
                    $positions[$key] = $building[$vertex];
                    // Поле, вернувшееся в укладку, цвет теряет: оно снова
                    // часть графа, а не отрезанный кусок на столе.
                    unset($groups[$key]);

                    continue;
                }

                $faded[$key] = true;
            }

            if ($built) {
                foreach ($plan->getCutout($id)->piece->edges as [$vertexA, $vertexB]) {
                    unset($groups[$plan->getCutout($id)->edges[Scene::edgeName($vertexA, $vertexB)]]);
                }
            }
        }

        $edges = [];

        foreach ($places['edges'] as $key => [$from, $to]) {
            $edges[$key] = [$positions[$from], $positions[$to]];

            if (isset($faded[$from]) || isset($faded[$to])) {
                $faded[$key] = true;
            }
        }

        return new Scene(
            vertexes: $positions,
            edges: $edges,
            groups: $groups,
            faded: $faded,
            weight: self::ACT_WEIGHT,
            kind: $stage->kind,
        );
    }

    /**
     * Кадр с сохранённым выделением: кольцо не гаснет, пока не сделано то,
     * ради чего выделяли.
     *
     * @param int[] $vertexes
     */
    private function withMark(Scene $scene, array $vertexes): Scene
    {
        $marked = array_flip($vertexes);
        $highlight = $scene->highlight;

        foreach (array_keys($scene->vertexes) as $key) {
            if (isset($marked[Scene::vertexOf($key)])) {
                $highlight[$key] = true;
            }
        }

        return new Scene(
            vertexes: $scene->vertexes,
            edges: $scene->edges,
            groups: $scene->groups,
            highlight: $highlight,
            faded: $scene->faded,
            weight: $scene->weight,
            kind: $scene->kind,
        );
    }

    /**
     * Сколько раз каждое ребро ещё предстоит отрезать.
     *
     * Ребро лежит ровно между двумя полями, поэтому его режут дважды:
     * по разу с каждой стороны.
     *
     * @param array<int, array{int, int}> $edges
     * @param Stage[] $stages
     *
     * @return array<string, int>
     */
    private function getCutCount(array $edges, array $stages): array
    {
        $result = [];

        foreach ($edges as [$vertexA, $vertexB]) {
            $result[Scene::edgeName($vertexA, $vertexB)] = 0;
        }

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Field) {
                continue;
            }

            foreach (Piece::ofWalk($stage->highlight)->edges as [$vertexA, $vertexB]) {
                $name = Scene::edgeName($vertexA, $vertexB);
                $result[$name] = ($result[$name] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * Каким по счёту шагом построения ляжет каждая вершина.
     *
     * @param Stage[] $stages
     *
     * @return array<int, int>
     */
    private function getBuildOrder(array $stages): array
    {
        $result = [];
        $step = 0;

        foreach ($stages as $stage) {
            if ($stage->kind !== StageKind::Build) {
                continue;
            }

            foreach ($stage->groups[0] ?? [] as $vertex) {
                $result[$vertex] ??= $step;
            }

            $step++;
        }

        return $result;
    }

    /**
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     *
     * @return string[]
     */
    private function getVertexKeys(array $places, int $vertex): array
    {
        $result = [];

        foreach ($places['pieces'] as $piece) {
            if (isset($piece[$vertex])) {
                $result[] = $piece[$vertex];
            }
        }

        return $result;
    }

    /**
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param array<string, int> $groups
     * @param array<string, true> $marked
     * @param array<string, true> $faded
     */
    private function getScene(array $places, StageKind $kind, array $groups, array $marked, array $faded, float $weight): Scene
    {
        $edges = [];

        foreach ($places['edges'] as $key => [$from, $to]) {
            $edges[$key] = [$places['positions'][$from], $places['positions'][$to]];
        }

        return new Scene(
            vertexes: $places['positions'],
            edges: $edges,
            groups: $groups,
            highlight: $marked,
            faded: $faded,
            weight: $weight,
            kind: $kind,
        );
    }

    /**
     * Кадры расслабления: промежуточные состояния остаются только там,
     * без чего рисунок по дороге пересёкся бы.
     *
     * Копии к этому времени уже съехались на свои вершины, поэтому здесь они
     * просто едут вместе с ними — и ничто не исчезает.
     *
     * @param Point2D[][] $frames
     * @param array<int, array{int, int}> $edges
     *
     * @return Scene[]
     */
    private function getMovingScenes(CutPlan $plan, array $frames, array $edges, int $from, int $to): array
    {
        $slice = array_slice($frames, $from, $to - $from + 1);

        if (count($slice) > $this->targetFrames) {
            $slice = $this->motion->thin($slice, $edges, $this->targetFrames);
        }

        $count = count($slice);
        $weight = self::MOVING_WEIGHT / max($count - 1, 1);
        $result = [];

        foreach ($slice as $position => $frame) {
            $places = $this->getPlaces($plan, $plan->getLastState(), $frame, [], 1.0);
            $result[] = $this->getScene(
                $this->getMergedPlaces($places, $frame),
                StageKind::Relax,
                [],
                [],
                [],
                // Первый кадр — та же собранная укладка, что и в конце сборки:
                // держать её ещё раз значит заморозить картинку перед самым
                // интересным.
                $position === 0 ? self::BEAT_WEIGHT : $weight,
            );
        }

        return $result;
    }

    /**
     * Все экземпляры на местах своих вершин: разрезанное собрано обратно.
     *
     * @param array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>} $places
     * @param Point2D[] $frame
     *
     * @return array{positions: array<string, Point2D>, edges: array<string, array{string, string}>, pieces: array<int, array<int, string>>, hosts: array<int, int>}
     */
    private function getMergedPlaces(array $places, array $frame): array
    {
        foreach ($places['pieces'] as $vertexes) {
            foreach ($vertexes as $vertex => $key) {
                if (isset($frame[$vertex])) {
                    $places['positions'][$key] = $frame[$vertex];
                }
            }
        }

        return $places;
    }

    /**
     * Насколько далеко друг от друга стоят вершины: по нему считаются
     * размеры разъехавшихся кусков.
     *
     * @param Point2D[] $points
     */
    private function getSpacing(array $points): float
    {
        $points = array_values($points);
        $count = count($points);

        if ($count < 2) {
            return 1.0;
        }

        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, sqrt(($points[$i]->x - $points[$j]->x) ** 2 + ($points[$i]->y - $points[$j]->y) ** 2));
            }
        }

        return $result === INF ? 1.0 : $result;
    }
}
