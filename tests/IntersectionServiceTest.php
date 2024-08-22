<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Intersection;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;

final class IntersectionServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getGetInnerIntersectionsData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws \Exception
     */
    public function testGetInnerIntersections(array $branch, array $path, array $outerVertexes, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));

        $actual = $this->getIntersectService()->getInnerIntersections($graph, $path, $outerVertexes, new SliceAggregate(new ZeroSlice()));

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getGetIntersectionMatrixData
     */
    public function testGetIntersectionMatrix(array $path, array $intersections, array $expected): void
    {
        $actual = $this->runPrivateMethod([$this->getIntersectService(), 'getIntersectionMatrix'], $path, $intersections);

        self::assertEquals($expected, self::graphToMatrix($actual));
    }

    /**
     * @dataProvider getGetIntersectionsData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testGetIntersections(array $branch, array $path, array $outerVertexes, array $expected): void
    {
        $graph = $this->arrayToDirectionGraph($branch);

        $actual = $this->runPrivateMethod([$this->getIntersectService(), 'getIntersections'], $graph, $path, $outerVertexes);

        $this->assertEquals(
            $expected,
            self::changeValue($actual, fn (Intersection $intersection) => self::printIntersect($intersection)),
        );
    }

    /**
     * @dataProvider getIsConflictedData
     */
    public function testIsConflicted(array $path, array $connectionsA, array $connectionsB, bool $expected): void
    {
        $actual = $this->runPrivateMethod([$this->getIntersectService(), 'isConflicted'], $connectionsA, $connectionsB, $path);

        $this->assertEquals($expected, $actual);
    }

    public static function getGetInnerIntersectionsData(): array
    {
        return [
            [
                self::getTriangleInTriangleInTriangle(),
                [3, 4, 5],
                [0 => true],
                [
                    new Intersection(
                        vertexes: [6, 7, 8],
                        connections: [3 => true, 4 => true, 5 => true],
                        isOuter: false,
                    ),
                ],
            ],
        ];
    }

    public static function getGetIntersectionMatrixData(): array
    {
        return [
            [
                [3, 4, 5],
                [
                    new Intersection(
                        vertexes: [0, 1, 2],
                        connections: [3 => true, 4 => true, 5 => true],
                        isOuter: true,
                    ),
                    new Intersection(
                        vertexes: [6, 7, 8],
                        connections: [3 => true, 4 => true, 5 => true],
                        isOuter: null,
                    ),
                ],
                [
                    ' |0|1',//todo why
                    '0| |1',
                    '1|1| ',
                ],
            ],
        ];
    }

    public static function getGetIntersectionsData(): array
    {
        return [
            [
                self::getTriangleInTriangle(),
                [0, 1, 2],
                [4 => true],
                [
                    [
                        'vertexes' => [3, 4, 5],
                        'connections' => [0 => true, 1 => true, 2 => true],
                        'is_outer' => true,
                    ],
                ],
            ],
            [
                self::getTriangleInTriangleInTriangle(),
                [3, 4, 5],
                [0 => true],
                [
                    [
                        'vertexes' => [0, 1, 2],
                        'connections' => [3 => true, 4 => true, 5 => true],
                        'is_outer' => true,
                    ],
                    [
                        'vertexes' => [6, 7, 8],
                        'connections' => [3 => true, 4 => true, 5 => true],
                        'is_outer' => null,
                    ],
                ],
            ],
        ];
    }

    public static function getIsConflictedData(): array
    {
        return [
            [
                [0, 1, 2],
                [0 => true, 1 => true, 2 => true],
                [0 => true, 1 => true, 2 => true],
                true,
            ],
            [
                [6, 0, 4, 1, 5, 2, 7],
                [0 => true, 1 => true, 2 => true],
                [0 => true, 1 => true, 2 => true],
                true,
            ],
            [
                [0, 1, 2],
                [0 => true, 1 => true, 2 => true],
                [0 => true, 1 => true, 2 => false],
                false,
            ],
            [
                [0, 1, 2],
                [0 => true, 1 => true, 2 => true],
                [0 => true, 1 => false, 2 => true],
                false,
            ],
            [
                [0, 1, 2],
                [0 => true, 1 => true, 2 => true],
                [0 => false, 1 => true, 2 => true],
                false,
            ],
        ];
    }

    private static function printIntersect(Intersection $intersection): array
    {
        return [
            'vertexes' => $intersection->vertexes,
            'connections' => $intersection->connections,
            'is_outer' => $intersection->isOuter ?? null,
        ];
    }
}