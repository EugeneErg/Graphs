<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ArticulationVertexesAggregateTest extends AbstractTestCase
{
    /**
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    #[DataProvider('getArticulationVertexesSuccessData')]
    public function testGetArticulationVertexesSuccess(array $connections, array $expected): void
    {
        $graph = $this->getGraphService()->createFromConnections($connections);

        $actual = (new ArticulationVertexesAggregate($graph))->articulationVertexes;
        sort($actual);

        self::assertEquals($expected, $actual);
    }

    public static function getArticulationVertexesSuccessData(): array
    {
        return [
            'triangle' => [
                self::getSimpleTriangle(),
                [],
            ],
            'line' => [
                self::getLine(),
                [],
            ],
            'three connected lines' => [
                self::getThreeLines(),
                [1, 2],
            ],
        ];
    }
}
