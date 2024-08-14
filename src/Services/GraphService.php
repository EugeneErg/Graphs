<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Graph;

readonly class GraphService
{
    public function __construct(
        public CanvasService $canvasService,
    ) {
    }

    /**
     * @param bool[][] $connections
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function createFromConnections(array $connections): Graph
    {
        $vertexes = array_keys($connections);

        foreach ($vertexes as $vertexA) {
            if (!is_int($vertexA)) {
                throw new InvalidVertexValueException();
            }

            foreach ($vertexes as $vertexB) {
                $connectionABExists = array_key_exists($vertexB, $connections[$vertexA]);

                if (array_key_exists($vertexA, $connections[$vertexB]) !== $connectionABExists) {
                    throw new InvalidConnectionException('Is direction graph.');
                }

                if (!$connectionABExists) {
                    continue;
                }

                if ($vertexA === $vertexB) {
                    throw new InvalidConnectionException('todo message 1');//todo
                }

                if ($connections[$vertexA][$vertexB] !== true) {
                    throw new InvalidConnectionException('Invalid connection value.');
                }
            }
        }

        return new Graph($connections, $vertexes);
    }

    /**
     * @return Graph[]
     */
    public function splitGraphOnDisconnected(Graph $graph): array
    {
        if ($graph->connections === []) {
            return [];
        }

        $canvas = new Canvas($graph);
        $operations = [];

        foreach ($graph->vertexes as $vertex) {
            if ($canvas->getPixel($vertex) === 0) {
                $vertexes = $this->canvasService->fill($canvas, $vertex, 1);
                $operations[] = $vertexes;
            }
        }

        if (count($operations) === 1) {
            return [$graph];
        }

        return array_map(fn (array $vertexes) => $this->createSubGraph($graph, $vertexes), $operations);
    }

    /**
     * @param int[] $vertexes
     */
    public function createSubGraph(Graph $graph, array $vertexes): Graph
    {
        $connections = array_fill_keys($vertexes, []);
        $size = count($vertexes);

        foreach ($vertexes as $posA => $vertexA) {
            for ($posB = $posA + 1; $posB < $size; $posB++) {
                $vertexB = $vertexes[$posB];

                if (isset($graph->connections[$vertexA][$vertexB])) {
                    $connections[$vertexA][$vertexB] = $connections[$vertexB][$vertexA] = $graph->connections[$vertexA][$vertexB];
                }
            }
        }

        return new Graph($connections, $vertexes);
    }
}
