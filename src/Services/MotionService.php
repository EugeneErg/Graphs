<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * Следит за тем, чтобы укладка оставалась плоской не только в кадрах,
 * но и всё время между ними.
 *
 * Картинка проигрывает переходы между кадрами по прямой, поэтому два чистых
 * кадра ещё ничего не гарантируют: посередине рёбра могут пересечься.
 * Здесь переходы проверяются целиком, а кадры прореживаются только там,
 * где это ничего не ломает.
 */
final readonly class MotionService
{
    public function __construct(private GeometryService $geometry = new GeometryService())
    {
    }

    /**
     * Остаётся ли рисунок плоским всё время перехода между двумя состояниями.
     *
     * @param Point2D[] $from
     * @param Point2D[] $to
     * @param array<int, array{int, int}> $edges
     */
    public function isTransitionPlanar(array $from, array $to, array $edges): bool
    {
        $edges = array_values(array_filter(
            $edges,
            static fn (array $edge): bool => isset($from[$edge[0]], $from[$edge[1]], $to[$edge[0]], $to[$edge[1]]),
        ));
        $count = count($edges);
        $boxes = [];

        foreach ($edges as $position => [$a, $b]) {
            $boxes[$position] = $this->getSweptBox($from[$a], $to[$a], $from[$b], $to[$b]);
        }

        for ($i = 0; $i < $count; $i++) {
            [$a, $b] = $edges[$i];

            for ($j = $i + 1; $j < $count; $j++) {
                [$c, $d] = $edges[$j];

                if ($a === $c || $a === $d || $b === $c || $b === $d) {
                    continue;
                }

                // Далёкие друг от друга рёбра за время перехода не встретятся.
                if (! $this->boxesOverlap($boxes[$i], $boxes[$j])) {
                    continue;
                }

                if ($this->geometry->segmentsIntersectDuringMotion(
                    $from[$a],
                    $to[$a],
                    $from[$b],
                    $to[$b],
                    $from[$c],
                    $to[$c],
                    $from[$d],
                    $to[$d],
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Прямоугольник, который ребро заметает за время перехода.
     *
     * @return array{float, float, float, float}
     */
    private function getSweptBox(Point2D ...$points): array
    {
        $xs = array_map(static fn (Point2D $point): float => $point->x, $points);
        $ys = array_map(static fn (Point2D $point): float => $point->y, $points);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * @param array{float, float, float, float} $first
     * @param array{float, float, float, float} $second
     */
    private function boxesOverlap(array $first, array $second): bool
    {
        return $first[0] <= $second[2] + GeometryService::EPSILON
            && $second[0] <= $first[2] + GeometryService::EPSILON
            && $first[1] <= $second[3] + GeometryService::EPSILON
            && $second[1] <= $first[3] + GeometryService::EPSILON;
    }

    /**
     * Наибольшая доля шага, на которой переход остаётся плоским.
     *
     * Ноль означает, что двигаться нельзя вовсе: исходное состояние плоское,
     * поэтому достаточно малый шаг всегда безопасен, но перебор долей ограничен.
     *
     * @param Point2D[] $from
     * @param Point2D[] $to
     * @param array<int, array{int, int}> $edges
     */
    public function getSafeRatio(array $from, array $to, array $edges, int $divisions = 6): float
    {
        for ($division = 0; $division <= $divisions; $division++) {
            $ratio = 1 / 2 ** $division;

            if ($this->isTransitionPlanar($from, $this->interpolate($from, $to, $ratio), $edges)) {
                return $ratio;
            }
        }

        return .0;
    }

    /**
     * Промежуточное состояние: каждая вершина проехала свою долю пути.
     *
     * @param Point2D[] $from
     * @param Point2D[] $to
     *
     * @return Point2D[]
     */
    public function interpolate(array $from, array $to, float $ratio): array
    {
        $result = [];

        foreach ($from as $vertex => $point) {
            $target = $to[$vertex] ?? $point;
            $result[$vertex] = new Point2D(
                $point->x + ($target->x - $point->x) * $ratio,
                $point->y + ($target->y - $point->y) * $ratio,
            );
        }

        return $result;
    }

    /**
     * Прореживает кадры, не ломая планарность движения.
     *
     * Кадр выбрасывается только если переход через него остаётся чистым.
     * Поэтому в спокойных местах кадров остаётся мало, а там, где вершины
     * расходятся тесно, — столько, сколько нужно.
     *
     * @param Point2D[][] $frames
     * @param array<int, array{int, int}> $edges
     *
     * @return Point2D[][]
     */
    public function thin(array $frames, array $edges, int $maxFrames): array
    {
        $frames = array_values($frames);
        $count = count($frames);

        if ($count <= 2 || $maxFrames < 2) {
            return $frames;
        }

        $step = max(1, (int) ceil(($count - 1) / max($maxFrames - 1, 1)));
        $result = [$frames[0]];
        $current = 0;

        while ($current < $count - 1) {
            $next = min($current + $step, $count - 1);

            // Слишком длинный прыжок откатываем, пока переход не станет чистым.
            while ($next > $current + 1 && ! $this->isTransitionPlanar($frames[$current], $frames[$next], $edges)) {
                $next = $current + (int) (($next - $current) / 2);
            }

            $result[] = $frames[$next];
            $current = $next;
        }

        return $result;
    }

    /**
     * Рёбра рисунка парами вершин.
     *
     * @param array<int, array<int, mixed>> $connections
     *
     * @return array<int, array{int, int}>
     */
    public function getEdges(array $connections): array
    {
        $result = [];

        foreach ($connections as $vertexA => $connection) {
            foreach (array_keys($connection) as $vertexB) {
                if ($vertexA < $vertexB) {
                    $result[] = [$vertexA, $vertexB];
                }
            }
        }

        return $result;
    }
}
