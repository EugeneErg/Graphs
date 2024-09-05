<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\GraphInterface;

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
        DirectionGraph $expectedTreeConnections,
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
            self::changeValue($expectedConnections, fn (GraphInterface $branch) => self::graphToMatrix($branch)),
            self::changeValue($actual->branches, fn (GraphInterface $branch) => self::graphToMatrix($branch)),
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
                        2 => [3 => true],
                        3 => [2 => true],
                    ],
                ],
                new DirectionGraph([
                    0 => [1 => 1],
                    1 => [0 => 1, 2 => 2],
                    2 => [1 => 2],
                ], [0, 1, 2]),
            ],
        ];
    }
}
