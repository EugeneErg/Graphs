<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Склейка ветвей в точках сочленения.
 *
 * Это не просто объединение списков граней: ветви связываются между собой
 * новыми рёбрами так, что односвязный граф становится двусвязным, а укладка
 * остаётся плоской. Без этого всё, что висит на точке сочленения, некуда
 * растягивать — оно схлопывается в саму точку. Добавленные рёбра в рисунок
 * не попадают: рисуется по-прежнему исходный граф, а грани нужны укладке.
 */
final class VertexServiceTest extends AbstractTestCase
{
    /**
     * @param Edge[][] $edgeList грани по ветвям
     * @param int[][] $treeConnections связь ветвей: ветвь => [ветвь => точка сочленения]
     * @param int[][] $original рёбра исходного графа
     */
    #[DataProvider('getMergeTreeData')]
    public function testMergeTreeTiesBranchesIntoBiconnectedGraph(
        array $edgeList,
        array $treeConnections,
        array $original,
    ): void {
        $faces = $this->glue($edgeList, $treeConnections);
        $usage = self::edgeUsage($faces);

        foreach ($usage as $edge => $count) {
            self::assertSame(2, $count, sprintf('Ребро %s встречается %d раз вместо двух.', $edge, $count));
        }

        foreach ($original as [$vertexA, $vertexB]) {
            self::assertArrayHasKey(
                self::edgeKey($vertexA, $vertexB),
                $usage,
                sprintf('Ребро %d-%d исходного графа потерялось.', $vertexA, $vertexB),
            );
        }

        $connections = self::facesToConnections($faces);

        self::assertCount(
            count($usage) - count($connections) + 2,
            $faces,
            'Граней должно быть ровно E − V + 2 — с учётом добавленных рёбер.',
        );
        self::assertTrue(
            self::isBiconnected($connections),
            'После склейки граф обязан стать двусвязным: на этом держится вся укладка.',
        );
    }

    /**
     * Ветви связываются новыми рёбрами — иначе двусвязным граф бы не стал.
     *
     * @param Edge[][] $edgeList
     * @param int[][] $treeConnections
     * @param int[][] $original
     */
    #[DataProvider('getMergeTreeData')]
    public function testMergeTreeAddsTiesBetweenBranches(array $edgeList, array $treeConnections, array $original): void
    {
        $added = array_diff_key(
            self::edgeUsage($this->glue($edgeList, $treeConnections)),
            array_flip(array_map(static fn (array $edge): string => self::edgeKey($edge[0], $edge[1]), $original)),
        );

        self::assertNotSame([], $added, 'Ветви должны быть связаны между собой.');
    }

    /**
     * Одиночный цикл ветви и есть вся укладка: делить его не на что.
     */
    public function testMergeTreeKeepsSingleCycleAsOneFace(): void
    {
        $faces = $this->glue([[new Edge([0, 1, 2])]], []);

        self::assertSame([[0, 1, 2]], array_map(static fn (Edge $edge): array => $edge->vertexes, $faces));
    }

    public function testMergeTreeKeepsSeveralFacesOfSingleBranchAsIs(): void
    {
        $faces = $this->glue([[new Edge([0, 1, 2]), new Edge([0, 2, 3])]], []);

        self::assertSame([[0, 1, 2], [0, 2, 3]], array_map(static fn (Edge $edge): array => $edge->vertexes, $faces));
    }

    /**
     * @return array<string, array{Edge[][], int[][], int[][]}>
     */
    public static function getMergeTreeData(): array
    {
        return [
            'два четырёхугольника через общую вершину 0' => [
                [
                    [new Edge([0, 1, 2, 3])],
                    [new Edge([4, 5, 6, 0])],
                ],
                [
                    0 => [1 => 0],
                    1 => [0 => 0],
                ],
                [[0, 1], [1, 2], [2, 3], [3, 0], [4, 5], [5, 6], [6, 0], [0, 4]],
            ],
            'три ребра в цепочку' => [
                [
                    [new Edge([0, 1])],
                    [new Edge([1, 2])],
                    [new Edge([2, 3])],
                ],
                [
                    0 => [1 => 1],
                    1 => [0 => 1, 2 => 2],
                    2 => [1 => 2],
                ],
                [[0, 1], [1, 2], [2, 3]],
            ],
            'треугольник с подвешенным ребром' => [
                [
                    [new Edge([0, 1, 2])],
                    [new Edge([2, 3])],
                ],
                [
                    0 => [1 => 2],
                    1 => [0 => 2],
                ],
                [[0, 1], [1, 2], [2, 0], [2, 3]],
            ],
        ];
    }

    /**
     * @param Edge[][] $edgeList
     * @param int[][] $treeConnections
     *
     * @return Edge[]
     */
    private function glue(array $edgeList, array $treeConnections): array
    {
        return $this->getVertexService()->mergeTree(
            $edgeList,
            new DirectionGraph($treeConnections, array_keys($treeConnections)),
            new SliceAggregate(new ZeroSlice()),
        );
    }

    /**
     * Двусвязен ли граф: нет вершины, удаление которой его разрывает.
     *
     * @param true[][] $connections
     */
    private static function isBiconnected(array $connections): bool
    {
        $vertexes = array_keys($connections);

        if (count($vertexes) < 3) {
            return true;
        }

        foreach ($vertexes as $skip) {
            $rest = array_values(array_diff($vertexes, [$skip]));
            $seen = [$rest[0] => true];
            $stack = [$rest[0]];

            while ($stack !== []) {
                $vertex = array_pop($stack);

                foreach (array_keys($connections[$vertex]) as $next) {
                    if ($next !== $skip && ! isset($seen[$next])) {
                        $seen[$next] = true;
                        $stack[] = $next;
                    }
                }
            }

            if (count($seen) !== count($rest)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Edge[] $faces
     *
     * @return array<string, int>
     */
    private static function edgeUsage(array $faces): array
    {
        $result = [];

        foreach ($faces as $face) {
            $vertexes = $face->vertexes;
            $count = count($vertexes);

            for ($i = 0; $i < $count; $i++) {
                $key = self::edgeKey($vertexes[$i], $vertexes[($i + 1) % $count]);
                $result[$key] = ($result[$key] ?? 0) + 1;
            }
        }

        return $result;
    }

    private static function edgeKey(int $vertexA, int $vertexB): string
    {
        return min($vertexA, $vertexB) . '-' . max($vertexA, $vertexB);
    }
}
