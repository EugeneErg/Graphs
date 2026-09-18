<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

/**
 * Отрезанный кусок графа: свои вершины и свои рёбра.
 *
 * Резать по-настоящему — значит разделять по границе. Вершина и ребро на
 * границе достаются обоим кускам, поэтому дальше они существуют в двух
 * экземплярах и связи с бывшим соседом у куска не остаётся.
 */
final readonly class Piece
{
    /**
     * @param int[] $vertexes
     * @param array<int, array{int, int}> $edges
     */
    public function __construct(public array $vertexes, public array $edges)
    {
    }

    /**
     * Кусок, составленный из рёбер.
     *
     * Вершины выстраиваются обходом по связям, а не по номерам: когда кусок
     * потом кладут на окружность, соседи оказываются рядом и рисунок куска
     * не превращается в звезду из пересечений.
     *
     * @param array<int, array{int, int}> $edges
     */
    public static function ofEdges(array $edges): self
    {
        $neighbours = [];

        foreach ($edges as [$vertexA, $vertexB]) {
            $neighbours[$vertexA][$vertexB] = true;
            $neighbours[$vertexB][$vertexA] = true;
        }

        ksort($neighbours);

        foreach ($neighbours as &$item) {
            ksort($item);
        }

        unset($item);

        return new self(self::getWalk($neighbours), array_values($edges));
    }

    /**
     * Кусок из замкнутого обхода: поле, ограниченное этим обходом.
     *
     * @param int[] $walk
     */
    public static function ofWalk(array $walk): self
    {
        $walk = array_values($walk);
        $count = count($walk);
        $edges = [];

        for ($i = 0; $i < $count; $i++) {
            $from = $walk[$i];
            $to = $walk[($i + 1) % $count];

            if ($from !== $to) {
                $edges[] = [min($from, $to), max($from, $to)];
            }
        }

        return new self(array_values(array_unique($walk)), $edges);
    }

    /**
     * Обход в глубину: идём к ближайшему непройденному соседу, а когда
     * упёрлись — возвращаемся назад. Для цикла это и есть сам цикл.
     *
     * @param array<int, array<int, true>> $neighbours
     *
     * @return int[]
     */
    private static function getWalk(array $neighbours): array
    {
        $result = [];
        $visited = [];

        foreach (array_keys($neighbours) as $start) {
            if (isset($visited[$start])) {
                continue;
            }

            $stack = [$start];
            $visited[$start] = true;

            while ($stack !== []) {
                $vertex = $stack[count($stack) - 1];
                $result[] = $vertex;
                $next = null;

                foreach (array_keys($neighbours[$vertex]) as $neighbour) {
                    if (! isset($visited[$neighbour])) {
                        $next = $neighbour;

                        break;
                    }
                }

                if ($next === null) {
                    array_pop($stack);

                    continue;
                }

                $visited[$next] = true;
                $stack[] = $next;
            }
        }

        return array_values(array_unique($result));
    }

    public function isEmpty(): bool
    {
        return $this->edges === [] && $this->vertexes === [];
    }
}
