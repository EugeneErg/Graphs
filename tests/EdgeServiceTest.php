<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\TreeEdge;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;
use Exception;

final class EdgeServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getGetPartEdgeData
     *
     * @param int[] $expected
     */
    public function testGetPartEdge(int $offset, bool $count, array $expected): void
    {
        $actual = $this->getEdgeService()->getPartEdge(new Edge([1, 2, 3, 4, 5]), $offset, $count ? 3 : -3);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getSplitOnTreeEdgesData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function testSplitOnTreeEdges(array $branch, TreeEdge $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));

        $actual = $this->getEdgeService()->splitOnTreeEdges($graph, new SliceAggregate(new ZeroSlice()));

        $this->assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getFindShortEdgeData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testFindShortEdge(array $branch, int $vertexA, int $vertexB, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));
        $true = true;

        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'findShortEdge'], $graph, $vertexA, $vertexB, $true);

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getFindShortPathData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testFindShortPath(array $branch, int $vertexA, int $vertexB, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));

        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'findShortPath'], $graph, $vertexA, $vertexB);

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getGetInnerVertexesData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testGetInnerVertexes(array $branch, array $path, array $outerVertexes, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));
        $slice = new SliceAggregate(new ZeroSlice());

        $actual = $this->runPrivateMethod(
            [$this->getEdgeService(), 'getInnerVertexes'],
            $graph,
            $path,
            $outerVertexes,
            $slice,
        );

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getPathToConnectionsData
     */
    public function testPathToConnections(array $path, array $expected): void
    {
        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'pathToConnections'], $path);

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getPathToOuterConnectionData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testPathToOuterConnection(array $path, array $branch, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));

        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'pathToOuterConnection'], $path, $graph);

        self::assertEquals($expected, $actual);
    }

    /**
     * @dataProvider getDisconnectVertexesData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testDisconnectVertexes(array $vertexes, array $branch, array $expected): void
    {
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));

        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'disconnectVertexes'], $vertexes, $graph);

        self::assertEquals($expected, $actual);
    }

    public static function getSplitOnTreeEdgesData(): array
    {
        return [
            [
                self::getRectangle(),
                new TreeEdge(new Edge([0, 3, 2, 1]), [
                    new TreeEdge(new Edge([0, 3, 2, 1])),
                ]),
            ],
            [
                self::getTriangleInTriangle(),
                new TreeEdge(new Edge([1, 4, 5, 2]), [
                    new TreeEdge(new Edge([0, 2, 1])),
                    new TreeEdge(new Edge([0, 1, 4, 3])),
                    new TreeEdge(new Edge([2, 0, 3, 5])),
                    new TreeEdge(new Edge([4, 3, 5])),
                ]),
            ],
            [
                self::getTriangleInTriangleInTriangle(),
                new TreeEdge(new Edge([1, 4, 5, 2]), [
                    new TreeEdge(new Edge([0, 2, 1])),
                    new TreeEdge(new Edge([0, 1, 4, 3])),
                    new TreeEdge(new Edge([2, 0, 3, 5])),
                    new TreeEdge(new Edge([4, 3, 5]), [
                        new TreeEdge(new Edge([4, 3, 6, 7])),
                        new TreeEdge(new Edge([5,4, 7, 8])),
                        new TreeEdge(new Edge([6, 7, 8])),
                        new TreeEdge(new Edge([3, 6, 8, 5])),
                    ]),
                ])
            ],
        ];
    }

    public static function getFindShortEdgeData(): array
    {
        return [
            [
                self::getTriangleInTriangle(),
                0,
                1,
                [0, 2, 1],
            ],
            [
                self::getTriangleInTriangleInTriangle(),
                2,
                5,
                [2, 0, 3, 5],
            ],
        ];
    }

    public static function getFindShortPathData(): array
    {
        return [
            [
                self::getTriangleInTriangle(),
                0,
                4,
                [0, 3, 4],
            ],
            [
                self::getTriangleInTriangleInTriangle(),
                2,
                8,
                [2, 5, 8],
            ],
        ];
    }

    public static function getGetInnerVertexesData(): array
    {
        return [
            [
                self::getTriangleInTriangleInTriangle(),
                [3, 4, 5],
                [0 => true],
                [6, 7, 8],
            ],
        ];
    }

    public static function getPathToConnectionsData(): array
    {
        return [
            [
                [0, 1, 2],
                [
                    0 => [2 => 2],
                    1 => [0 => 2],
                    2 => [1 => 2],
                ],
            ],
        ];
    }

    public static function getPathToOuterConnectionData(): array
    {
        return [
            [
                [0, 1, 2],
                self::getTriangleInTriangle(),
                [
                    0 => [2 => 2],
                    1 => [0 => 2],
                    2 => [1 => 2],
                ],
            ],
        ];
    }

    public static function getDisconnectVertexesData(): array
    {
        return [
            [
                [1],
                self::getRectangle(),
                [
                    1 => [0 => null, 2 => null],
                ],
            ],
            [
                [1, 2],
                self::getRectangle(),
                [
                    1 => [0 => null, 2 => null],
                    2 => [1 => null, 3 => null],
                ],
            ],
            [
                [0, 1, 2],
                self::getTriangleInTriangle(),
                [
                    0 => [1 => null, 2 => null, 3 => null],
                    1 => [0 => null, 2 => null, 4 => null],
                    2 => [0 => null, 1 => null, 5 => null],
                ],
            ]
        ];
    }

    public static function getGetPartEdgeData(): array
    {
        return [
            [
                0,
                true,
                [1, 2, 3],
            ],
            [
                1,
                true,
                [2, 3, 4],
            ],
            [
                2,
                true,
                [3, 4, 5],
            ],
            [
                3,
                true,
                [4, 5, 1],
            ],
            [
                4,
                true,
                [5, 1, 2],
            ],
            [
                0,
                false,
                [1, 5, 4],
            ],
            [
                1,
                false,
                [2, 1, 5],
            ],
            [
                2,
                false,
                [3, 2, 1],
            ],
            [
                3,
                false,
                [4, 3, 2],
            ],
            [
                4,
                false,
                [5, 4, 3],
            ],
        ];
    }
}
