<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\GravityVertexes;
use EugeneErg\Graphs\ValueObjects\Topology;
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
        $actual = $this->getPlanarService()->connectionsToSvg($connections, new SliceAggregate(new ZeroSlice()));

        $this->assertEquals($expected, $actual);
    }

    public static function getConnectionsToSwgData(): array
    {
        return [
            [
                self::getBig1(),
                [
                    new Topology(
                        new Edge([0, 6, 12, 18, 17, 11, 5]),
                        [
                            new Arc(new GravityVertexes(0, 6, 5), [[5, 4, 3, 2], [1, 7, 6]]),
                            new Arc(new GravityVertexes(0, 6, 5), [[0, 1]]),
                            new Arc(new GravityVertexes(6), [[7, 13, 12]]),
                            new Arc(new GravityVertexes(1), [[2, 8, 7]]),
                            new Arc(new GravityVertexes(2), [[3, 9, 8]]),
                            new Arc(new GravityVertexes(3), [[4, 10, 9]]),
                            new Arc(new GravityVertexes(4), [[11, 10]]),
                            new Arc(new GravityVertexes(7), [[8, 14, 13]]),
                            new Arc(new GravityVertexes(12), [[13, 19, 18]]),
                            new Arc(new GravityVertexes(8), [[9, 15, 14]]),
                            new Arc(new GravityVertexes(9), [[10, 16, 15]]),
                            new Arc(new GravityVertexes(10), [[17, 16]]),

                        ],
                    ),
                ],
            ],
            /*[
                self::getSmallTree(),
                [],
            ],*/
        ];

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
