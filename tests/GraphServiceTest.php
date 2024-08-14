<?php

declare(strict_types = 1);

namespace Tests;

use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Graph;

final class GraphServiceTest extends AbstractTestCase
{
    /**
     * @throws InvalidVertexValueException
     * @throws InvalidConnectionException
     */
    public function testCreateFromConnectionsFailVertexValue(): void
    {
        $this->expectException(InvalidVertexValueException::class);

        $this->getGraphService()->createFromConnections([
            'fail' => [],
        ]);
    }

    /**
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testCreateFromConnectionsFailDirection(): void
    {
        $this->expectException(InvalidConnectionException::class);
        $this->expectExceptionMessage('Is direction graph.');

        $this->getGraphService()->createFromConnections([
            0 => [1 => true],
            1 => [],
        ]);
    }

    /**
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testCreateFromConnectionsFail1(): void
    {
        $this->expectException(InvalidConnectionException::class);
        $this->expectExceptionMessage('todo message 1');

        $this->getGraphService()->createFromConnections([
            0 => [0 => true],
        ]);
    }

    /**
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testCreateFromConnectionsFailConnectionValue(): void
    {
        $this->expectException(InvalidConnectionException::class);
        $this->expectExceptionMessage('Invalid connection value.');

        $this->getGraphService()->createFromConnections([
            0 => [1 => false],
            1 => [0 => true],
        ]);
    }

    /**
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testCreateFromConnectionsSuccess(): void
    {
        $connections = self::getSimpleTriangle();

        $actual = $this->getGraphService()->createFromConnections($connections);

        self:;self::assertEquals(new Graph($connections, [0, 1, 2]), $actual);
    }

    /**
     * @dataProvider getSplitGraphOnDisconnectedData
     * @param bool[][] $connection
     * @param Graph[] $expectedGraphs
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function testSplitGraphOnDisconnected(array $connection, array $expectedGraphs): void
    {
        $graphService = $this->getGraphService();
        $graph = $graphService->createFromConnections($connection);

        $actual = $graphService->splitGraphOnDisconnected($graph);

        self::assertEquals(
            array_map(fn (array $connections) => $this->graphToMatrix($graphService->createFromConnections($connections)), $expectedGraphs),
            array_map(fn (Graph $graph) => $this->graphToMatrix($graph), $actual),
        );
    }

    public static function getSplitGraphOnDisconnectedData(): array
    {
        return [
            'simple triangle' => [
                self::getSimpleTriangle(),
                [
                    self::getSimpleTriangle(),
                ],
            ],
            'dot and line' => [
                self::merge(false, self::getDot(), self::getLine()),
                [
                    self::getDot(),
                    self::getLine(1),
                ],
            ],
            'rect and triangle' => [
                self::merge(false, self::getRectangle(), self::getSimpleTriangle()),
                [
                    self::getRectangle(),
                    self::getSimpleTriangle(4),
                ],
            ],
        ];
    }

    private static function assertEqualsGraphs(Graph $graphA, Graph $graphB): void
    {
        $matrixA = self::graphToMatrix($graphA);
        $matrixB = self::graphToMatrix($graphB);

        if ($matrixA !== $matrixB) {
            self::fail(self::printGraphCompare($graphA, $matrixA, $graphB, $matrixB));
        }
    }

    private static function printGraphCompare(Graph $graphA, string $matrixA, Graph $graphB, string $matrixB): string
    {
        $sizeA = count($graphA->vertexes) + 1;
        $sizeB = count($graphB->vertexes) + 1;
        $diff = abs($sizeA - $sizeB);
        $linesA = explode("\r\n", $matrixA);
        $linesB = explode("\r\n", $matrixB);
        $lengthA = empty($linesA) ? 0 : strlen($linesA[0]);
        $lengthB = empty($linesB) ? 0 : strlen($linesB[0]);
        $add = array_fill(0, $diff, str_repeat(' ', $sizeA < $sizeB ? $lengthA : $lengthB));
        array_splice($sizeA < $sizeB ? $linesA : $linesB, intdiv($diff, 2), 0, $add);
        $result = [];
        $center = intdiv(count($graphB->vertexes) + 1, 2);

        foreach ($linesA as $row => $lineA) {
            $result[] = $lineA . ($row === $center ? ' != ' : '    ') . $linesB[$row];
        }

        return implode("\r\n", $result);
    }
}