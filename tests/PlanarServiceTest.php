<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;

final class PlanarServiceTest extends AbstractTestCase
{
    /**
     * @dataProvider getConnectionsToSwgData
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testConnectionsToSwg(array $connections, array $expected): void
    {
        $actual = $this->getPlanarService()->connectionsToSwg($connections, new SliceAggregate(new ZeroSlice()));

        $this->assertEquals($expected, $actual);
    }

    public static function getConnectionsToSwgData(): array
    {
        return [
            [
                self::getBig1(),
                [
                    [
                        new Edge([0, 6, 12, 18, 17, 11, 5]),
                        new Edge([0, 6, 7, 1]),
                        new Edge([0, 1, 2, 3, 4, 5]),
                        new Edge([6, 7, 13, 12]),
                        new Edge([7, 1, 2, 8]),
                        new Edge([3, 2, 8, 9]),
                        new Edge([4, 3, 9, 10]),
                        new Edge([5, 4, 10, 11]),
                        new Edge([13, 7, 8, 14]),
                        new Edge([12, 13, 19, 18]),
                        new Edge([9, 8, 14, 15]),
                        new Edge([10, 9, 15, 16]),
                        new Edge([11, 10, 16, 17]),
                        new Edge([18, 19, 13, 14, 15, 16, 17]),
                    ],
                ],
            ],
            [
                self::getSmallTree(),
                [
                    [
                        new Edge([6, 5, 4, 7]),
                        new Edge([0, 4, 3, 2, 1]),
                        new Edge([4, 0, 1, 2, 5]),
                        new Edge([5, 2, 3, 6]),
                        new Edge([6, 3, 4, 7]),
                    ],
                ],
            ],
        ];
    }
}
