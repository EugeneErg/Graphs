<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Point2D;
use LogicException;

/**
 * Плоская геометрия, на которой стоит расслабление укладки.
 *
 * Сервис ничего не знает о графе: он работает с точками и многоугольниками.
 */
final readonly class GeometryService
{
    public const float EPSILON = 1.0e-9;

    /**
     * Удвоенная ориентированная площадь многоугольника.
     * Положительна для обхода против часовой стрелки в обычной системе координат.
     *
     * @param Point2D[] $polygon
     */
    public function doubleArea(array $polygon): float
    {
        $polygon = array_values($polygon);
        $count = count($polygon);
        $result = .0;

        for ($i = 0; $i < $count; $i++) {
            $current = $polygon[$i];
            $next = $polygon[($i + 1) % $count];
            $result += $current->x * $next->y - $next->x * $current->y;
        }

        return $result;
    }

    /**
     * Центр масс площади многоугольника.
     *
     * Среднее по вершинам не годится: оно смещается туда, где вершины стоят чаще,
     * и вершина продолжает липнуть к плотному участку.
     *
     * @param Point2D[] $polygon
     */
    public function centroid(array $polygon): Point2D
    {
        $polygon = array_values($polygon);
        $count = count($polygon);

        if ($count === 0) {
            throw new LogicException('Центр масс пустого многоугольника не определён.');
        }

        $doubleArea = $this->doubleArea($polygon);

        if (abs($doubleArea) < self::EPSILON) {
            return $this->averagePoint($polygon);
        }

        $x = .0;
        $y = .0;

        for ($i = 0; $i < $count; $i++) {
            $current = $polygon[$i];
            $next = $polygon[($i + 1) % $count];
            $cross = $current->x * $next->y - $next->x * $current->y;
            $x += ($current->x + $next->x) * $cross;
            $y += ($current->y + $next->y) * $cross;
        }

        return new Point2D($x / (3 * $doubleArea), $y / (3 * $doubleArea));
    }

    /**
     * @param Point2D[] $points
     */
    public function averagePoint(array $points): Point2D
    {
        $count = count($points);

        if ($count === 0) {
            throw new LogicException('Средняя точка пустого набора не определена.');
        }

        $x = .0;
        $y = .0;

        foreach ($points as $point) {
            $x += $point->x;
            $y += $point->y;
        }

        return new Point2D($x / $count, $y / $count);
    }

    public function distance(Point2D $a, Point2D $b): float
    {
        return sqrt($this->squaredDistance($a, $b));
    }

    public function squaredDistance(Point2D $a, Point2D $b): float
    {
        return ($a->x - $b->x) ** 2 + ($a->y - $b->y) ** 2;
    }

    /**
     * Ближайшая к точке точка отрезка: по ней меряют, насколько вершина
     * подошла к чужому ребру.
     */
    public function closestOnSegment(Point2D $point, Point2D $a, Point2D $b): Point2D
    {
        $x = $b->x - $a->x;
        $y = $b->y - $a->y;
        $length = $x ** 2 + $y ** 2;

        if ($length < self::EPSILON) {
            return $a;
        }

        $ratio = max(.0, min(1.0, (($point->x - $a->x) * $x + ($point->y - $a->y) * $y) / $length));

        return new Point2D($a->x + $x * $ratio, $a->y + $y * $ratio);
    }

    /**
     * Расстояние от точки до отрезка.
     */
    public function distanceToSegment(Point2D $point, Point2D $a, Point2D $b): float
    {
        return $this->distance($point, $this->closestOnSegment($point, $a, $b));
    }

    /**
     * Знак векторного произведения ab × ac: с какой стороны от прямой ab лежит c.
     */
    public function direction(Point2D $a, Point2D $b, Point2D $c): float
    {
        return ($b->x - $a->x) * ($c->y - $a->y) - ($b->y - $a->y) * ($c->x - $a->x);
    }

    /**
     * Строгое пересечение отрезков: касания концами и наложения не считаются.
     * Именно это нужно сторожу планарности — смежные рёбра имеют общую вершину.
     */
    public function segmentsIntersect(Point2D $a, Point2D $b, Point2D $c, Point2D $d): bool
    {
        $d1 = $this->direction($c, $d, $a);
        $d2 = $this->direction($c, $d, $b);
        $d3 = $this->direction($a, $b, $c);
        $d4 = $this->direction($a, $b, $d);

        return $d1 * $d2 < -self::EPSILON && $d3 * $d4 < -self::EPSILON;
    }

    /**
     * Пересекутся ли отрезки хоть в один момент, пока их концы едут по прямой
     * из начальных положений в конечные.
     *
     * Проверять только концы движения мало: два чистых кадра соединяются
     * прямолинейным переходом, и рисунок может потерять планарность посередине.
     *
     * Ориентация тройки равномерно движущихся точек — квадратный трёхчлен от
     * времени, а признак пересечения зависит только от знаков четырёх таких
     * трёхчленов. Знаки меняются лишь в их корнях, поэтому достаточно разбить
     * отрезок времени корнями и проверить признак внутри каждого куска.
     *
     * @param Point2D $a0 начало первого конца первого отрезка
     * @param Point2D $a1 конец его движения
     */
    public function segmentsIntersectDuringMotion(
        Point2D $a0,
        Point2D $a1,
        Point2D $b0,
        Point2D $b1,
        Point2D $c0,
        Point2D $c1,
        Point2D $d0,
        Point2D $d1,
    ): bool {
        $quadratics = [
            $this->directionQuadratic($c0, $c1, $d0, $d1, $a0, $a1),
            $this->directionQuadratic($c0, $c1, $d0, $d1, $b0, $b1),
            $this->directionQuadratic($a0, $a1, $b0, $b1, $c0, $c1),
            $this->directionQuadratic($a0, $a1, $b0, $b1, $d0, $d1),
        ];

        $moments = [.0, 1.0];

        foreach ($quadratics as $quadratic) {
            foreach ($this->getRoots($quadratic) as $root) {
                $moments[] = $root;
            }
        }

        sort($moments);
        $count = count($moments);

        for ($i = 0; $i < $count - 1; $i++) {
            $moment = ($moments[$i] + $moments[$i + 1]) / 2;
            $first = $this->valueAt($quadratics[0], $moment) * $this->valueAt($quadratics[1], $moment);
            $second = $this->valueAt($quadratics[2], $moment) * $this->valueAt($quadratics[3], $moment);

            if ($first < -self::EPSILON && $second < -self::EPSILON) {
                return true;
            }
        }

        return false;
    }

    /**
     * Коэффициенты ориентации тройки движущихся точек как многочлена от времени.
     *
     * @return array{float, float, float}
     */
    private function directionQuadratic(
        Point2D $a0,
        Point2D $a1,
        Point2D $b0,
        Point2D $b1,
        Point2D $c0,
        Point2D $c1,
    ): array {
        $firstX = $b0->x - $a0->x;
        $firstY = $b0->y - $a0->y;
        $firstSpeedX = ($b1->x - $b0->x) - ($a1->x - $a0->x);
        $firstSpeedY = ($b1->y - $b0->y) - ($a1->y - $a0->y);
        $secondX = $c0->x - $a0->x;
        $secondY = $c0->y - $a0->y;
        $secondSpeedX = ($c1->x - $c0->x) - ($a1->x - $a0->x);
        $secondSpeedY = ($c1->y - $c0->y) - ($a1->y - $a0->y);

        return [
            $firstX * $secondY - $firstY * $secondX,
            $firstX * $secondSpeedY + $firstSpeedX * $secondY - $firstY * $secondSpeedX - $firstSpeedY * $secondX,
            $firstSpeedX * $secondSpeedY - $firstSpeedY * $secondSpeedX,
        ];
    }

    /**
     * @param array{float, float, float} $quadratic
     */
    private function valueAt(array $quadratic, float $moment): float
    {
        [$free, $linear, $square] = $quadratic;

        return $free + $linear * $moment + $square * $moment ** 2;
    }

    /**
     * Корни многочлена внутри отрезка времени.
     *
     * @param array{float, float, float} $quadratic
     *
     * @return float[]
     */
    private function getRoots(array $quadratic): array
    {
        [$free, $linear, $square] = $quadratic;

        if (abs($square) < self::EPSILON) {
            if (abs($linear) < self::EPSILON) {
                return [];
            }

            $root = -$free / $linear;

            return $root > 0 && $root < 1 ? [$root] : [];
        }

        $discriminant = $linear ** 2 - 4 * $square * $free;

        if ($discriminant < 0) {
            return [];
        }

        $rootOfDiscriminant = sqrt($discriminant);
        $result = [];

        foreach ([(-$linear - $rootOfDiscriminant) / (2 * $square), (-$linear + $rootOfDiscriminant) / (2 * $square)] as $root) {
            if ($root > 0 && $root < 1) {
                $result[] = $root;
            }
        }

        return $result;
    }

    /**
     * Точка внутри многоугольника (трассировка луча). Точки на границе не считаются внутренними.
     *
     * @param Point2D[] $polygon
     */
    public function isPointInPolygon(Point2D $point, array $polygon): bool
    {
        $polygon = array_values($polygon);
        $count = count($polygon);
        $inside = false;

        for ($i = 0; $i < $count; $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % $count];

            if ($this->isPointOnSegment($point, $a, $b)) {
                return false;
            }

            if (($a->y > $point->y) !== ($b->y > $point->y)) {
                $x = ($b->x - $a->x) * ($point->y - $a->y) / ($b->y - $a->y) + $a->x;

                if ($point->x < $x) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    public function isPointOnSegment(Point2D $point, Point2D $a, Point2D $b): bool
    {
        if (abs($this->direction($a, $b, $point)) > self::EPSILON * max(1.0, $this->squaredDistance($a, $b))) {
            return false;
        }

        return $point->x >= min($a->x, $b->x) - self::EPSILON
            && $point->x <= max($a->x, $b->x) + self::EPSILON
            && $point->y >= min($a->y, $b->y) - self::EPSILON
            && $point->y <= max($a->y, $b->y) + self::EPSILON;
    }

    /**
     * Многоугольник видимости точки внутри простого многоугольника.
     *
     * Пускаем лучи в каждую вершину и чуть левее и правее неё: за углом луч
     * уходит дальше и находит стену позади, из-за которой угол загораживает обзор.
     * Отсортированные по углу точки попадания и образуют границу видимой области.
     *
     * @param Point2D[] $polygon
     *
     * @return Point2D[]
     */
    public function visibilityPolygon(Point2D $origin, array $polygon): array
    {
        $polygon = array_values($polygon);
        $count = count($polygon);

        if ($count < 3) {
            return [];
        }

        $shift = 1.0e-6;
        $angles = [];

        foreach ($polygon as $vertex) {
            $angle = atan2($vertex->y - $origin->y, $vertex->x - $origin->x);
            $angles[] = $angle - $shift;
            $angles[] = $angle;
            $angles[] = $angle + $shift;
        }

        sort($angles);
        $result = [];

        foreach ($angles as $angle) {
            $hit = $this->castRay($origin, $angle, $polygon);

            if ($hit === null) {
                continue;
            }

            $last = end($result);

            if ($last === false || $this->squaredDistance($last, $hit) > self::EPSILON) {
                $result[] = $hit;
            }
        }

        if (count($result) > 2) {
            /** @var Point2D $first */
            $first = reset($result);
            /** @var Point2D $last */
            $last = end($result);

            if ($this->squaredDistance($first, $last) <= self::EPSILON) {
                array_pop($result);
            }
        }

        return count($result) < 3 ? [] : $result;
    }

    /**
     * Обрезает отрезок по границе многоугольника: возвращает самую дальнюю точку
     * на пути к цели, до которой ещё можно дойти, не пересекая границу.
     * Отступ не даёт встать вплотную к стене.
     *
     * @param Point2D[] $polygon
     */
    public function clipToPolygon(Point2D $origin, Point2D $target, array $polygon, float $margin = 0.9): Point2D
    {
        $length = $this->distance($origin, $target);

        if ($length < self::EPSILON) {
            return $origin;
        }

        $angle = atan2($target->y - $origin->y, $target->x - $origin->x);
        $wall = $this->rayLength($origin, $angle, array_values($polygon));

        if ($wall === null || $wall >= $length) {
            return $target;
        }

        $ratio = $wall * $margin / $length;

        return new Point2D(
            $origin->x + ($target->x - $origin->x) * $ratio,
            $origin->y + ($target->y - $origin->y) * $ratio,
        );
    }

    /**
     * Ближайшая к началу точка попадания луча в границу многоугольника.
     *
     * @param Point2D[] $polygon
     */
    private function castRay(Point2D $origin, float $angle, array $polygon): ?Point2D
    {
        $nearest = $this->rayLength($origin, $angle, $polygon);

        return $nearest === null
            ? null
            : new Point2D($origin->x + cos($angle) * $nearest, $origin->y + sin($angle) * $nearest);
    }

    /**
     * Расстояние от точки до границы многоугольника в заданном направлении.
     *
     * @param Point2D[] $polygon
     */
    private function rayLength(Point2D $origin, float $angle, array $polygon): ?float
    {
        $dirX = cos($angle);
        $dirY = sin($angle);
        $count = count($polygon);
        $nearest = null;

        for ($i = 0; $i < $count; $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % $count];
            $segmentX = $b->x - $a->x;
            $segmentY = $b->y - $a->y;
            $denominator = $dirX * $segmentY - $dirY * $segmentX;

            if (abs($denominator) < self::EPSILON) {
                continue;
            }

            $deltaX = $a->x - $origin->x;
            $deltaY = $a->y - $origin->y;
            $rayLength = ($deltaX * $segmentY - $deltaY * $segmentX) / $denominator;
            $segmentPosition = ($deltaX * $dirY - $deltaY * $dirX) / $denominator;

            if ($rayLength <= self::EPSILON || $segmentPosition < 0 || $segmentPosition > 1) {
                continue;
            }

            if ($nearest === null || $rayLength < $nearest) {
                $nearest = $rayLength;
            }
        }

        return $nearest;
    }
}
