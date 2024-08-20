<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;

final class EdgeServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getGetIntersectionMatrixData
     */
    public function testGetIntersectionMatrix(array $path, array $intersections, array $expected): void
    {
        $actual = $this->runPrivateMethod([$this->getEdgeService(), 'getIntersectionMatrix'], $path, $intersections);

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

    public static function getGetIntersectionMatrixData(): array
    {
        return [
            [

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
}
