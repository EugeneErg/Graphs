<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Graph;

final class TreeServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getFromConnectionGraphSuccessData
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testFromConnectionGraphSuccess(
        array $connections,
        array $expectedBranches,
        array $expectedTreeConnections,
    ): void {
        $graphService = $this->getGraphService();
        $graph = $graphService->createFromConnections($connections);
        $articulationVertexesAggregate = new ArticulationVertexesAggregate($graph);
        $expectedConnections = self::changeValue(
            $expectedBranches,
            fn (array $branch) => $graphService->createFromConnections($branch),
        );

        $actual = $this->getTreeService()->fromConnectionGraph($articulationVertexesAggregate);

        self::assertEquals($graph, $actual->graph);
        self::assertEquals(
            self::changeValue($expectedConnections, fn (Graph $branch) => self::graphToMatrix($branch)),
            self::changeValue($actual->branches, fn (Graph $branch) => self::graphToMatrix($branch)),
        );
        self::assertEquals($expectedTreeConnections, $actual->connections);
    }

    public static function getFromConnectionGraphSuccessData(): array
    {
        return [
            [
                self::getThreeLines(),
                [
                    [
                        0 => [1 => true],
                        1 => [0 => true],
                    ],
                    [
                        1 => [2 => true],
                        2 => [1 => true],
                    ],
                    [
                        2 => [],
                        3 => [],
                    ],
                ],
                [
                    [0],
                    [0, 1],
                    [1, 2],
                    [2],
                ],
            ],
        ];
    }
}