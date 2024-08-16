<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use ReflectionException;
use ReflectionMethod;

final class EdgeServiceTest extends AbstractTestCase
{
    public function testSplitOnTreeEdges(): void
    {

    }

    /**
     * @dataProvider getDisconnectVertexesData
     *
     * @throws ReflectionException
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testDisconnectVertexes(array $vertexes, array $branch, array $expected): void
    {
        $edgeService = $this->getEdgeService();
        $graph = $this->getGraphService()->graphToDirection($this->getGraphService()->createFromConnections($branch));
        $method = new ReflectionMethod(...[$edgeService, 'disconnectVertexes']);
        $method->setAccessible(true);

        $actual = $method->invoke($edgeService, $vertexes, $graph);

        self::assertEquals($expected, $actual);
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
                [1,  2],
                self::getRectangle(),
                [
                    1 => [0 => null, 2 => null],
                    2 => [1 => null, 3 => null],
                ],
            ],
        ];
    }
}
