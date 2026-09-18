<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\ValueObjects\Angle;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityInterface;
use EugeneErg\Graphs\ValueObjects\Point2D;
use EugeneErg\Graphs\ValueObjects\StageKind;
use EugeneErg\Graphs\ValueObjects\Topology;

/**
 * Расстановка вершин плоской укладки.
 *
 * Сначала строится начальная раскладка: внешняя грань ложится на окружность,
 * внутренние вершины подвешиваются на дуги Безье. Такая раскладка планарна,
 * но сжата: чем больше вершин, тем плотнее они слипаются у центра.
 *
 * Дальше идёт расслабление. Каждая внутренняя вершина видит вокруг себя
 * многоугольник из смежных граней и переезжает в центр масс той его части,
 * которую реально видит. Шаг принимается, только если укладка осталась плоской,
 * иначе он уменьшается вдвое. Планарность поэтому не теряется ни на одном кадре.
 */
final readonly class CoordinateService
{
    /** Сколько кругов чистки делать после того, как силы успокоились. */
    private const int POLISH_ROUNDS = 60;

    /** По скольким направлениям вершина пробует отойти. */
    private const int POLISH_DIRECTIONS = 16;

    /** Сколько раз укорачивается пробный шаг. */
    private const int POLISH_DIVISIONS = 4;

    public function __construct(
        private GeometryService $geometry = new GeometryService(),
        private int $maxIterations = 800,
        private float $accuracy = 0.01,
        private int $maxStepDivisions = 6,
        private float $cooling = 0.985,
        private MotionService $motion = new MotionService(),
    ) {
    }

    /**
     * @return Point2D[]
     */
    public function getCoordinates(
        Topology $topology,
        float $radius,
        ?Point2D $center = null,
        ?Trace $trace = null,
    ): array {
        $result = $this->getCircle($topology->outerEdge->vertexes, $radius, $center);
        $trace?->add(
            StageKind::Build,
            sprintf('Кладём внешнюю грань на окружность: %s', implode(' - ', $topology->outerEdge->vertexes)),
            [array_keys($result)],
        );

        foreach ($topology->arcs as $number => $arc) {
            $indexes = $this->getIndexes($arc);
            $begin = $result[$arc->firstVertex()];
            $end = $result[$arc->lastVertex()];
            $gravityCenter = $this->getGravityCenter($arc->gravity, $result);
            $added = [];

            foreach ($indexes as $vertex => $index) {
                if (!isset($result[$vertex])) {
                    $result[$vertex] = $this->bezierPoint($begin, $gravityCenter, $end, $index);
                    $added[] = $vertex;
                }
            }

            // Поля ложатся по одному, и каждое видно отдельным шагом.
            $trace?->add(
                StageKind::Build,
                sprintf(
                    'Добавляем поле %d: %s',
                    $number + 1,
                    implode(' - ', array_merge(...$arc->vertexes)),
                ),
                [array_keys($result)],
                $added,
            );
        }

        return $result;
    }

    /**
     * Все вершины на одной окружности в порядке номеров: то самое спутанное
     * начальное состояние, из которого укладка потом распутывается.
     *
     * @param int[] $vertexes
     *
     * @return Point2D[]
     */
    public function getTangledCoordinates(array $vertexes, float $radius, ?Point2D $center = null): array
    {
        sort($vertexes);

        return $this->getCircle($vertexes, $radius, $center);
    }

    /**
     * Барицентрическая укладка Татта: внешняя грань остаётся на месте,
     * каждая внутренняя вершина садится в среднее своих соседей.
     *
     * Это распрямляет начальную догадку в честную плоскую укладку с нужной
     * топологией. Вершины при этом сгущаются в плотных местах — расходятся
     * они уже на расслаблении.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    public function getBarycentricCoordinates(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $outerVertexes = array_flip($outerEdge->vertexes);
        $neighbours = array_diff_key($this->getNeighbours($edges), $outerVertexes);

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $shift = .0;

            foreach ($neighbours as $vertex => $vertexNeighbours) {
                if ($vertexNeighbours === []) {
                    continue;
                }

                $moved = $this->geometry->averagePoint(
                    array_map(static fn (int $item): Point2D => $coordinates[$item], $vertexNeighbours),
                );
                $shift = max($shift, $this->geometry->distance($coordinates[$vertex], $moved));
                $coordinates[$vertex] = $moved;
            }

            if ($shift < $this->accuracy) {
                break;
            }
        }

        return $coordinates;
    }

    /**
     * Итоговая расслабленная укладка.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[]
     */
    public function relaxCoordinates(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $steps = $this->relaxSteps($outerEdge, $edges, $coordinates);

        return $steps[count($steps) - 1];
    }

    /**
     * Все промежуточные состояния расслабления, начиная с исходного.
     * Нужны для анимации: каждый элемент — кадр.
     *
     * @param Edge[] $edges внутренние грани
     * @param Point2D[] $coordinates
     *
     * @return Point2D[][]
     */
    public function relaxSteps(Edge $outerEdge, array $edges, array $coordinates): array
    {
        $faces = $this->orientFaces($edges, $coordinates);
        $flats = $this->getFlats($outerEdge, $faces);
        $neighbours = $this->getNeighbours($faces);
        $idealLength = $this->getIdealLength($outerEdge, $coordinates);
        $temperature = $idealLength;
        $steps = [$coordinates];
        $bestStep = 0;
        $edges = $this->motion->getEdges($this->getNeighbours(array_merge([$outerEdge], $faces)));
        $bestScore = $this->getScore($coordinates, $edges);

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $previous = $coordinates;
            $shift = .0;

            foreach ($flats as $vertex => $flat) {
                $moved = $this->relaxVertex(
                    $vertex,
                    $flat,
                    $neighbours[$vertex] ?? [],
                    $coordinates,
                    $idealLength,
                    $temperature,
                );

                if ($moved === null) {
                    continue;
                }

                $shift = max($shift, $this->geometry->distance($coordinates[$vertex], $moved));
                $coordinates[$vertex] = $moved;
            }

            // Каждая вершина по отдельности шагает законно, но едут они разом,
            // и по дороге рисунок может пересечься. Укорачиваем шаг всей волны,
            // пока переход целиком не станет плоским.
            $ratio = $this->motion->getSafeRatio($previous, $coordinates, $edges, $this->maxStepDivisions);

            if ($ratio < 1.0) {
                $coordinates = $ratio > 0
                    ? $this->motion->interpolate($previous, $coordinates, $ratio)
                    : $previous;
                $shift *= $ratio;
            }

            $steps[] = $coordinates;
            $score = $this->getScore($coordinates, $edges);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStep = count($steps) - 1;
            }

            // Пока укладка заметно двигается, температуру держим: в тесных местах
            // вершины расходятся медленно, и раннее остывание оставляет их слипшимися.
            if ($shift < $temperature * 0.6) {
                $temperature *= $this->cooling;
            }

            if ($shift < $this->accuracy) {
                break;
            }
        }

        // Силовая модель по дороге раскачивается, поэтому дальше идёт не
        // последнее состояние, а лучшее из встреченных: результат не бывает
        // хуже начального.
        $steps = array_slice($steps, 0, $bestStep + 1);

        return $this->polishSteps($steps, $flats, $neighbours, $edges, $idealLength, $bestScore);
    }

    /**
     * Чистка: развести вершины с чужих рёбер.
     *
     * Силы уравнивают длины рёбер, но не видят главного огреха читаемости —
     * вершины, лежащей на чужом ребре. Расстояние до соседних вершин при этом
     * может быть каким угодно большим, а выглядит всё равно как пересечение,
     * которого нет. Поэтому каждая вершина пробует отойти в ту сторону, где
     * её просвет до чужих рёбер больше, и шаг принимается только если укладка
     * от него выиграла.
     *
     * @param Point2D[][] $steps
     * @param array<int, int[]> $flats
     * @param array<int, int[]> $neighbours
     * @param array<int, array{int, int}> $edges
     *
     * @return Point2D[][]
     */
    private function polishSteps(
        array $steps,
        array $flats,
        array $neighbours,
        array $edges,
        float $idealLength,
        float $bestScore,
    ): array {
        $coordinates = $steps[count($steps) - 1];
        $bestStep = count($steps) - 1;
        $step = $idealLength / 2;

        for ($iteration = 0; $iteration < self::POLISH_ROUNDS; $iteration++) {
            $previous = $coordinates;
            $shift = .0;
            $score = $this->getScore($coordinates, $edges);

            foreach ($flats as $vertex => $flat) {
                $moved = $this->polishVertex($vertex, $flat, $neighbours[$vertex] ?? [], $coordinates, $step);

                if ($moved === null) {
                    continue;
                }

                // Вершине виден только её угол картинки, поэтому её шаг
                // принимается, лишь если укладке в целом не стало хуже:
                // разводя одну пару, легко свести другую.
                $was = $coordinates[$vertex];
                $coordinates[$vertex] = $moved;
                $now = $this->getScore($coordinates, $edges);

                if ($now < $score - GeometryService::EPSILON) {
                    $coordinates[$vertex] = $was;

                    continue;
                }

                $score = max($score, $now);
                $shift = max($shift, $this->geometry->distance($was, $moved));
            }

            $ratio = $this->motion->getSafeRatio($previous, $coordinates, $edges, $this->maxStepDivisions);

            if ($ratio < 1.0) {
                $coordinates = $ratio > 0
                    ? $this->motion->interpolate($previous, $coordinates, $ratio)
                    : $previous;
                $shift *= $ratio;
            }

            $steps[] = $coordinates;
            $score = $this->getScore($coordinates, $edges);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStep = count($steps) - 1;
            }

            if ($shift < $this->accuracy) {
                $step /= 2;

                if ($step < $this->accuracy) {
                    break;
                }
            }
        }

        return array_slice($steps, 0, $bestStep + 1);
    }

    /**
     * Куда вершине отойти, чтобы просвет вокруг неё стал больше.
     *
     * Перебираются направления вокруг вершины; принимается то, где местный
     * просвет наибольший, а укладка осталась плоской. Если лучше некуда,
     * вершина остаётся на месте.
     *
     * @param int[] $flat
     * @param int[] $neighbours
     * @param Point2D[] $coordinates
     */
    private function polishVertex(int $vertex, array $flat, array $neighbours, array $coordinates, float $step): ?Point2D
    {
        $origin = $coordinates[$vertex];
        $polygon = array_map(static fn (int $item): Point2D => $coordinates[$item], $flat);
        $visible = $this->geometry->visibilityPolygon($origin, $polygon);

        if ($visible === []) {
            return null;
        }

        $neighbourPoints = array_map(static fn (int $item): Point2D => $coordinates[$item], $neighbours);
        $best = $this->getLocalScore($origin, $neighbourPoints, $polygon);
        $result = null;

        for ($direction = 0; $direction < self::POLISH_DIRECTIONS; $direction++) {
            $angle = 2 * M_PI * $direction / self::POLISH_DIRECTIONS;

            for ($division = 0; $division < self::POLISH_DIVISIONS; $division++) {
                $length = $step / (2 ** $division);
                $candidate = new Point2D($origin->x + cos($angle) * $length, $origin->y + sin($angle) * $length);
                $score = $this->getLocalScore($candidate, $neighbourPoints, $polygon);

                if ($score <= $best
                    || ! $this->geometry->isPointInPolygon($candidate, $visible)
                    || ! $this->isPlanarPosition($candidate, $polygon, $neighbourPoints)
                ) {
                    continue;
                }

                $best = $score;
                $result = $candidate;
            }
        }

        return $result;
    }

    /**
     * Просвет вокруг вершины: насколько она далека от стен своей комнаты
     * и насколько её собственные рёбра далеки от чужих углов.
     *
     * @param Point2D[] $neighbours
     * @param Point2D[] $polygon
     */
    private function getLocalScore(Point2D $point, array $neighbours, array $polygon): float
    {
        $count = count($polygon);
        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            $result = min($result, $this->geometry->distanceToSegment($point, $polygon[$i], $polygon[($i + 1) % $count]));
        }

        foreach ($neighbours as $end) {
            foreach ($polygon as $corner) {
                if ($this->geometry->distance($corner, $end) > GeometryService::EPSILON) {
                    $result = min($result, $this->geometry->distanceToSegment($corner, $point, $end));
                }
            }
        }

        return $result === INF ? .0 : $result;
    }

    /**
     * Отталкивание от стен своей комнаты.
     *
     * Соседние вершины могут разъехаться как угодно далеко, а вершина всё
     * равно будет лежать на чужом ребре: расстояние до вершин и расстояние
     * до рёбер — разные вещи. Стены комнаты — это и есть ближайшие чужие
     * рёбра, дальше них вершина всё равно не уходит, поэтому достаточно
     * отталкиваться от них.
     *
     * @param Point2D[] $polygon
     *
     * @return array{float, float}
     */
    private function getWallForce(
        Point2D $origin,
        array $polygon,
        float $idealLength,
    ): array {
        $count = count($polygon);
        $x = .0;
        $y = .0;

        for ($i = 0; $i < $count; $i++) {
            $from = $polygon[$i];
            $to = $polygon[($i + 1) % $count];

            // Стены комнаты — это рёбра, не приходящие в саму вершину: она
            // внутри. Если вдруг стена всё же в неё упирается, отталкиваться
            // от такой стены нельзя — своё ребро должно оставаться коротким.
            if ($this->geometry->distance($origin, $from) < GeometryService::EPSILON
                || $this->geometry->distance($origin, $to) < GeometryService::EPSILON
            ) {
                continue;
            }

            $closest = $this->geometry->closestOnSegment($origin, $from, $to);
            $distance = $this->geometry->distance($origin, $closest);

            if ($distance < GeometryService::EPSILON || $distance > $idealLength) {
                continue;
            }

            $force = $idealLength ** 2 / $distance ** 2;
            $x += ($origin->x - $closest->x) / $distance * $force;
            $y += ($origin->y - $closest->y) / $distance * $force;
        }

        return [$x, $y];
    }

    /**
     * Новое положение вершины или null, если сдвинуться некуда.
     *
     * Силы задают направление, многоугольник видимости — предел: дальше стены
     * вершина не уходит. Если и укороченный шаг ломает планарность, он делится
     * пополам, пока не станет допустимым.
     *
     * @param int[] $flat многоугольник из смежных граней
     * @param int[] $neighbours
     * @param Point2D[] $coordinates
     */
    private function relaxVertex(
        int $vertex,
        array $flat,
        array $neighbours,
        array $coordinates,
        float $idealLength,
        float $temperature,
    ): ?Point2D {
        $origin = $coordinates[$vertex];
        $polygon = array_map(static fn (int $item): Point2D => $coordinates[$item], $flat);
        $visible = $this->geometry->visibilityPolygon($origin, $polygon);

        if ($visible === []) {
            return null;
        }

        $target = $this->geometry->clipToPolygon(
            $origin,
            $this->getForceTarget($vertex, $neighbours, $coordinates, $polygon, $idealLength, $temperature),
            $visible,
        );
        $neighbourPoints = array_map(static fn (int $item): Point2D => $coordinates[$item], $neighbours);

        for ($division = 0; $division <= $this->maxStepDivisions; $division++) {
            $ratio = 1 / (2 ** $division);
            $candidate = new Point2D(
                $origin->x + ($target->x - $origin->x) * $ratio,
                $origin->y + ($target->y - $origin->y) * $ratio,
            );

            if ($this->geometry->distance($origin, $candidate) < $this->accuracy) {
                return null;
            }

            if ($this->isPlanarPosition($candidate, $polygon, $neighbourPoints)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Куда вершину тянут силы: рёбра притягивают к идеальной длине,
     * остальные вершины отталкивают. Температура ограничивает длину шага
     * и остывает от итерации к итерации, чтобы укладка сходилась.
     *
     * @param int[] $neighbours
     * @param Point2D[] $coordinates
     * @param Point2D[] $polygon стены комнаты, в которой живёт вершина
     */
    private function getForceTarget(
        int $vertex,
        array $neighbours,
        array $coordinates,
        array $polygon,
        float $idealLength,
        float $temperature,
    ): Point2D {
        $origin = $coordinates[$vertex];
        [$x, $y] = $this->getWallForce($origin, $polygon, $idealLength);

        foreach ($coordinates as $other => $point) {
            if ($other === $vertex) {
                continue;
            }

            $distance = $this->geometry->distance($origin, $point);

            if ($distance < GeometryService::EPSILON) {
                continue;
            }

            $force = $idealLength ** 2 / $distance ** 2;
            $x += ($origin->x - $point->x) / $distance * $force;
            $y += ($origin->y - $point->y) / $distance * $force;
        }

        foreach ($neighbours as $neighbour) {
            $point = $coordinates[$neighbour];
            $distance = $this->geometry->distance($origin, $point);

            if ($distance < GeometryService::EPSILON) {
                continue;
            }

            $force = $distance ** 2 / $idealLength;
            $x += ($point->x - $origin->x) / $distance * $force;
            $y += ($point->y - $origin->y) / $distance * $force;
        }

        $length = sqrt($x ** 2 + $y ** 2);

        if ($length > $temperature) {
            $x *= $temperature / $length;
            $y *= $temperature / $length;
        }

        return new Point2D($origin->x + $x, $origin->y + $y);
    }

    /**
     * Насколько укладка читаема: чем дальше ближайшие вершины друг от друга
     * и от чужих рёбер, тем меньше нужно приближать картинку.
     *
     * Одного расстояния между вершинами мало: вершина может стоять далеко
     * от всех вершин и при этом лежать на чужом ребре — и выглядит это как
     * пересечение, которого нет.
     *
     * @param Point2D[] $coordinates
     * @param array<int, array{int, int}> $edges
     */
    private function getScore(array $coordinates, array $edges): float
    {
        $points = array_values($coordinates);
        $count = count($points);
        $result = INF;

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $result = min($result, $this->geometry->distance($points[$i], $points[$j]));
            }
        }

        foreach ($coordinates as $vertex => $point) {
            foreach ($edges as [$vertexA, $vertexB]) {
                if ($vertex !== $vertexA && $vertex !== $vertexB) {
                    $result = min($result, $this->geometry->distanceToSegment($point, $coordinates[$vertexA], $coordinates[$vertexB]));
                }
            }
        }

        return $result === INF ? .0 : $result;
    }

    /**
     * Длина ребра, при которой вершины равномерно заполняют внешнюю грань.
     *
     * @param Point2D[] $coordinates
     */
    private function getIdealLength(Edge $outerEdge, array $coordinates): float
    {
        $polygon = array_map(static fn (int $vertex): Point2D => $coordinates[$vertex], $outerEdge->vertexes);
        $area = abs($this->geometry->doubleArea($polygon)) / 2;
        $count = max(count($coordinates), 1);

        return $area > 0 ? sqrt($area / $count) : 1.0;
    }

    /**
     * Сторож планарности: вершина должна остаться строго внутри своего
     * многоугольника, а её рёбра — не пересечь его границу. Выйти за пределы
     * этого многоугольника ребро может только пересекая границу, поэтому
     * локальной проверки достаточно: остальной рисунок вершина не задевает.
     *
     * @param Point2D[] $polygon
     * @param Point2D[] $neighbours
     */
    private function isPlanarPosition(Point2D $candidate, array $polygon, array $neighbours): bool
    {
        if (! $this->geometry->isPointInPolygon($candidate, $polygon)) {
            return false;
        }

        $count = count($polygon);

        foreach ($neighbours as $neighbour) {
            for ($i = 0; $i < $count; $i++) {
                if ($this->geometry->segmentsIntersect($candidate, $neighbour, $polygon[$i], $polygon[($i + 1) % $count])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Приводит все грани к единому обходу против часовой стрелки:
     * склейка граней в многоугольник работает только на согласованных обходах.
     *
     * @param Edge[] $edges
     * @param Point2D[] $coordinates
     *
     * @return Edge[]
     */
    private function orientFaces(array $edges, array $coordinates): array
    {
        $result = [];

        foreach ($edges as $edge) {
            $polygon = array_map(static fn (int $vertex): Point2D => $coordinates[$vertex], $edge->vertexes);
            $result[] = $this->geometry->doubleArea($polygon) < 0
                ? new Edge(array_reverse($edge->vertexes))
                : $edge;
        }

        return $result;
    }

    /**
     * Многоугольник вокруг каждой внутренней вершины: объединение смежных граней
     * без самой вершины. Вершины внешней грани закреплены и не расслабляются.
     *
     * @param Edge[] $faces
     *
     * @return int[][] вершина => обход её многоугольника
     */
    private function getFlats(Edge $outerEdge, array $faces): array
    {
        $outerVertexes = array_flip($outerEdge->vertexes);
        /** @var Edge[][] $rooms */
        $rooms = [];

        foreach ($faces as $face) {
            foreach ($face->vertexes as $vertex) {
                if (! isset($outerVertexes[$vertex])) {
                    $rooms[$vertex][] = $face;
                }
            }
        }

        $result = [];

        foreach ($rooms as $vertex => $room) {
            $flat = $this->mergeRooms($room, $vertex);

            if ($flat !== null) {
                $result[$vertex] = $flat;
            }
        }

        return $result;
    }

    /**
     * Соседи каждой вершины по граням.
     *
     * Сосед стоит и ключом, и значением: значения нужны обходу соседей,
     * а ключи — построению списка рёбер (`MotionService::getEdges`).
     *
     * @param Edge[] $faces
     *
     * @return array<int, array<int, int>>
     */
    private function getNeighbours(array $faces): array
    {
        $result = [];

        foreach ($faces as $face) {
            $vertexes = $face->vertexes;
            $count = count($vertexes);

            for ($i = 0; $i < $count; $i++) {
                $current = $vertexes[$i];
                $next = $vertexes[($i + 1) % $count];
                $result[$current][$next] = $next;
                $result[$next][$current] = $current;
            }
        }

        return $result;
    }

    /**
     * Обходит грани вокруг вершины по кругу и склеивает их в один многоугольник.
     *
     * Из каждой грани берётся кусок от следующей за вершиной точки и до
     * предыдущей, не включая её: предыдущая точка — это стык со следующей гранью,
     * она войдёт в обход как её начало.
     *
     * @param Edge[] $edges
     *
     * @return int[]|null null, если грани вокруг вершины не образуют простого многоугольника
     */
    private function mergeRooms(array $edges, int $vertex): array|null
    {
        $edges = array_values($edges);
        $positions = [];
        $lines = [];

        foreach ($edges as $num => $edge) {
            $position = array_search($vertex, $edge->vertexes, true);

            if (! is_int($position) || count($edge->vertexes) < 3) {
                return null;
            }

            $previous = $edge->getVertex($position - 1);
            $next = $edge->getVertex($position + 1);
            $lines[$next] = $num;
            $positions[$num] = ['pos' => $position, 'vertex' => $previous];
        }

        $result = [];
        $edgeCount = count($edges);
        $current = 0;

        for ($i = 0; $i < $edgeCount; $i++) {
            ['pos' => $position, 'vertex' => $previous] = $positions[$current];
            $edge = $edges[$current];
            $result[] = $edge->getVertexes($position + 1, count($edge->vertexes) - 2);

            if (! isset($lines[$previous])) {
                return null;
            }

            $current = $lines[$previous];
        }

        if ($current !== 0) {
            return null;
        }

        $flat = array_merge(...$result);

        return count($flat) < 3 || count(array_unique($flat)) !== count($flat) ? null : $flat;
    }

    /**
     * @param int[] $vertexes
     *
     * @return Point2D[]
     */
    private function getCircle(array $vertexes, float $radius, ?Point2D $center = null, ?Angle $startAngle = null): array
    {
        $vertexes = array_values(array_unique($vertexes));
        $vertexCount = count($vertexes);

        if ($vertexCount === 0) {
            return [];
        }

        $center ??= new Point2D();

        if ($vertexCount === 1) {
            return array_fill_keys($vertexes, $center);
        }

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
            $total = count($vertexes) * $count;
            $prevCount = $num1 * count($vertexes);

            foreach ($vertexes as $num2 => $vertex) {
                // Единственная точка дуги не с чем распределять — ставим её посередине.
                $result[$vertex] = $total > 1 ? (float) ($num2 + $prevCount) / ($total - 1) : 0.5;
            }
        }

        return $result;
    }

    /**
     * @param Point2D[] $coordinates
     */
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
