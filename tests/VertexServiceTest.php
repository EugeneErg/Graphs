<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;

final class VertexServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getMergeTreeData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testMergeTree(array $edgeList, array $graph, array $expected): void
    {
        $connections = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($graph));

        $actual = $this->getVertexService()->mergeTree($edgeList, $connections, new SliceAggregate(new ZeroSlice()));

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getAddEdgeToMapData
     */
    public function testAddEdgeToMap(array $edgeList, array $vertexes, int $offset, array $expected): void
    {
        $actual = $this->runPrivateMethod([$this->getVertexService(), 'addEdgeToMap'], $edgeList, $vertexes, $offset);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getGetEdgeFromWWData
     */
    public function testGetEdgeFromWW(array $edgeA, array $edgeB, array $expected): void
    {
        $edgeA = new Edge($edgeA);
        $edgeB = new Edge($edgeB);
        $vertex = 0;

        $actual = $this->runPrivateMethod([$this->getVertexService(), 'getEdgeFromWW'], $vertex, $edgeA, $edgeB);

        $this->assertEquals($expected, $actual->vertexes);
    }

    /**
     * @dataProvider getGetEdgesFromWVData
     */
    public function testGetEdgesFromWV(array $edgeA, array $edgeB, array $expected): void
    {
        $edgeA = new Edge($edgeA);
        $edgeB = new Edge($edgeB);
        $vertex = 0;

        $actual = $this->runPrivateMethod([$this->getVertexService(), 'getEdgesFromWV'], $vertex, $edgeA, $edgeB);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getGetEdgesFromVVData
     */
    public function testGetEdgesFromVV(array $edgeA, array $edgeB, array $expected): void
    {
        $edgeA = new Edge($edgeA);
        $edgeB = new Edge($edgeB);
        $vertex = 0;

        $actual = $this->runPrivateMethod([$this->getVertexService(), 'getEdgesFromVV'], $vertex, $edgeA, $edgeB);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getDelEdgeFromMapData
     */
    public function testDelEdgeFromMap(array $edgeList, int $branch, array $edgeMap, array $expected): void
    {
        $this->runPrivateMethod([$this->getVertexService(), 'delEdgeFromMap'], $edgeList, $branch, $edgeMap);

        $this->assertEquals($expected, $edgeMap);
    }

    /**
     * @dataProvider getMoveEdgeInMapData
     */
    public function testMoveEdgeInMap(int $branch, int $root, int $edgeException, array $edgeMap, array $expected): void
    {
        $this->runPrivateMethod([$this->getVertexService(), 'moveEdgeInMap'], $branch, $root, $edgeException, $edgeMap);

        $this->assertEquals($expected, $edgeMap);
    }

    public static function getMergeTreeData(): array
    {
        return [
            [
                [
                    [new Edge([0, 1, 2, 3])],
                    [new Edge([4, 5, 6, 0])],
                ],
                [
                    0 => [1 => true],
                    1 => [0 => true],
                ],
                [
                    new Edge([0, 1, 2, 3]),
                    new Edge([4, 5, 6, 0]),
                    new Edge([0, 1, 2, 3]),
                    new Edge([4, 5, 6, 0]),
                ],
            ],
        ];
    }

    public static function getAddEdgeToMapData(): array
    {
        return [
            [
                [
                    0 => new Edge([1, 2, 3]),
                    1 => new Edge([5, 6, 7]),
                ],
                [2, 7, 8],
                0,
                [
                    2 => [
                        0 => new Edge([1, 2, 3]),
                    ],
                    7 => [
                        1 => new Edge([5, 6, 7]),
                    ],
                ],
            ],
        ];
    }

    public static function getGetEdgeFromWWData(): array
    {
        return [
            [
                [0, 1, 2, 3, 4],
                [5, 6, 7, 8, 0],
                [0, 5, 6, 2, 1],
            ],
            [
                [1, 2, 0, 3, 4],
                [5, 6, 0, 7, 8],
                [0, 7, 8, 4, 3],
            ],
            [

                [5, 6, 7, 8, 0],
                [0, 1, 2, 3, 4],
                [0, 1, 2, 6, 5],
            ],
        ];
    }

    public static function getGetEdgesFromWVData(): array
    {
        return [
            [
                [0, 1, 2, 3, 4],
                [5, 6, 7, 8, 0],
                [new Edge([0, 5, 6, 2, 1]), new Edge([0, 8, 7, 6, 2, 1])],
            ],
            [
                [1, 2, 0, 3, 4],
                [5, 6, 0, 7, 8],
                [new Edge([0, 7, 8, 4, 3]), new Edge([0, 6, 5, 8, 4, 3])],
            ],
            [
                [5, 6, 7, 8, 0],
                [0, 1, 2, 3, 4],
                [new Edge([0, 1, 2, 6, 5]), new Edge([0, 4, 3, 2, 6, 5])],
            ],
        ];
    }

    public static function getGetEdgesFromVVData(): array
    {
        return [
            [
                [0, 1, 2, 3, 4],
                [5, 6, 7, 8, 0],
                [new Edge([0, 5, 6, 2, 1]), new Edge([0, 8, 7, 6, 2, 3, 4])],
            ],
            [
                [1, 2, 0, 3, 4],
                [5, 6, 0, 7, 8],
                [new Edge([0, 7, 8, 4, 3]), new Edge([0, 6, 5, 8, 4, 1, 2])],
            ],
            [
                [5, 6, 7, 8, 0],
                [0, 1, 2, 3, 4],
                [new Edge([0, 1, 2, 6, 5]), new Edge([0, 4, 3, 2, 6, 7, 8])],
            ],
        ];
    }

    public static function getDelEdgeFromMapData(): array
    {
        return [
            [
                [0 => new Edge([1, 2, 3])],
                0,
                [
                    0 => [
                        1 => [
                            0 => true,
                            1 => true,
                        ],
                    ],
                    1 => [
                        1 => [
                            0 => true,
                        ],
                    ],
                ],
                [
                    0 => [
                        1 => [
                            1 => true,
                        ],
                    ],
                    1 => [
                        1 => [
                            0 => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getMoveEdgeInMapData(): array
    {
        return [
            'Empty edge map' => [
                'branch' => 1,
                'root' => 2,
                'edgeException' => 0,
                'edgeMap' => [1 => []],
                'expected' => [],
            ],
            'Move edges from branch to root without exceptions' => [
                'branch' => 1,
                'root' => 2,
                'edgeException' => 999, // Edge number that doesn't exist
                'edgeMap' => [
                    1 => [
                        'A' => [
                            0 => 'edgeA1',
                            1 => 'edgeA2',
                        ],
                        'B' => [
                            0 => 'edgeB1',
                        ],
                    ],
                ],
                'expected' => [
                    2 => [
                        'A' => [
                            0 => 'edgeA1',
                            1 => 'edgeA2',
                        ],
                        'B' => [
                            0 => 'edgeB1',
                        ],
                    ],
                ],
            ],
            'Move edges with an exception' => [
                'branch' => 1,
                'root' => 2,
                'edgeException' => 1, // Edge number 1 should be ignored
                'edgeMap' => [
                    1 => [
                        'A' => [
                            0 => 'edgeA1',
                            1 => 'edgeA2',
                            2 => 'edgeA3',
                        ],
                        'B' => [
                            0 => 'edgeB1',
                        ],
                    ],
                ],
                'expected' => [
                    1 => [
                        'A' => [
                            1 => 'edgeA2', // Only edge 1 should remain
                        ],
                    ],
                    2 => [
                        'A' => [
                            0 => 'edgeA1',
                            2 => 'edgeA3',
                        ],
                        'B' => [
                            0 => 'edgeB1',
                        ],
                    ],
                ],
            ],
            'All edges are exceptions' => [
                'branch' => 1,
                'root' => 2,
                'edgeException' => 0, // All edges will be ignored
                'edgeMap' => [
                    1 => [
                        'A' => [
                            0 => 'edgeA1',
                        ],
                    ],
                ],
                'expected' => [
                    1 => [
                        'A' => [
                            0 => 'edgeA1',
                        ],
                    ],
                ],
            ],
            'Move edges when only one edge is in the map' => [
                'branch' => 1,
                'root' => 2,
                'edgeException' => 999, // No edge should be ignored
                'edgeMap' => [
                    1 => [
                        'A' => [
                            0 => 'edgeA1',
                        ],
                    ],
                ],
                'expected' => [
                    2 => [
                        'A' => [
                            0 => 'edgeA1',
                        ],
                    ],
                ],
            ],
        ];
    }
}
