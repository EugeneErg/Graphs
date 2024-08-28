<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Graph;
use EugeneErg\Graphs\ValueObjects\GraphInterface;

readonly class GraphService
{
    public function __construct(
        public CanvasService $canvasService,
    ) {
    }

    public function direct(DirectionGraph $graph, int $vertex): DirectionGraph
    {
        $parent = null;
        $new = clone $graph;

        for ($vertexes = [$vertex => $parent]; $vertex !== null; $vertex = key($vertexes)) {
            foreach ($graph->getConnection($vertex) ?? [] as $vertexB => $value) {
                if ($vertexB !== $parent) {
                    $new->unsetValue($vertexB, $vertex);
                    $vertexes[$vertexB] = $vertex;
                }
            }

            $parent = next($vertexes);
        }

        return $new;
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
        if ($graph->getConnections() === []) {
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
     * todo return class of $graph
     * @param GraphInterface $graph
     * @param int[] $vertexes
     * @return GraphInterface
     */
    public function createSubGraph(GraphInterface $graph, array $vertexes): GraphInterface
    {
        $connections = array_fill_keys($vertexes, []);
        $size = count($vertexes);

        foreach ($vertexes as $posA => $vertexA) {
            for ($posB = $posA + 1; $posB < $size; $posB++) {
                $vertexB = $vertexes[$posB];

                if ($graph->hasValue($vertexA, $vertexB)) {
                    $connections[$vertexA][$vertexB] = $connections[$vertexB][$vertexA] = $graph->getValue($vertexA,$vertexB);
                }
            }
        }

        return new $graph($connections, $vertexes);
    }

    public function graphToDirection(Graph $graph): DirectionGraph
    {
        $connections = [];

        foreach ($graph->getConnections() as $vertexA => $connection) {
            foreach ($connection as $vertexB => $value) {
                $connections[$vertexA][$vertexB] = (int) $value;
            }
        }

        return new DirectionGraph($connections, $graph->vertexes);
    }
}
