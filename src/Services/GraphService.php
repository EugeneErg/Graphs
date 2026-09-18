<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\Canvas;
use EugeneErg\Graphs\Aggregates\Trace;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Graph;
use EugeneErg\Graphs\ValueObjects\GraphInterface;
use EugeneErg\Graphs\ValueObjects\StageKind;

readonly class GraphService
{
    public function __construct(
        public CanvasService $canvasService,
    ) {
    }

    /**
     * Направляет связи прочь от корня: обход в ширину снимает обратное ребро
     * у каждой пройденной связи.
     */
    public function direct(DirectionGraph $graph, int $vertex): DirectionGraph
    {
        $new = clone $graph;
        /** @var array<int, int|null> $parents */
        $parents = [$vertex => null];
        $queue = [$vertex];

        for ($i = 0; $i < count($queue); $i++) {
            $current = $queue[$i];
            $parent = $parents[$current];

            foreach (array_keys($graph->getConnection($current) ?? []) as $next) {
                if ($next === $parent) {
                    continue;
                }

                $new->unsetValue($next, $current, true);

                if (! array_key_exists($next, $parents)) {
                    $parents[$next] = $current;
                    $queue[] = $next;
                }
            }
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
            if (! is_int($vertexA)) {
                throw new InvalidVertexValueException();
            }

            foreach ($vertexes as $vertexB) {
                $connectionABExists = array_key_exists($vertexB, $connections[$vertexA]);

                if (array_key_exists($vertexA, $connections[$vertexB]) !== $connectionABExists) {
                    throw new InvalidConnectionException('Is direction graph.');
                }

                if (! $connectionABExists) {
                    continue;
                }

                if ($vertexA === $vertexB) {
                    throw new InvalidConnectionException('todo message 1'); //todo
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
    public function splitGraphOnDisconnected(Graph $graph, ?Trace $trace = null): array
    {
        if ($graph->getConnections() === []) {
            return [];
        }

        $canvas = new Canvas($graph);
        $operations = [];

        foreach ($graph->vertexes as $vertex) {
            if ($canvas->getPixel($vertex) === 0) {
                // Каждому куску свой цвет, чтобы на картинке они не слились.
                $vertexes = $this->canvasService->fill($canvas, $vertex, count($operations) + 1, $trace);
                $operations[] = $vertexes;
            }
        }

        $trace?->add(
            StageKind::Components,
            count($operations) === 1
                ? 'Граф связный: кусок один'
                : sprintf('Несвязных кусков: %d — разводим по сторонам', count($operations)),
            $operations,
        );

        if (count($operations) === 1) {
            return [$graph];
        }

        /** @var Graph[] $result */
        $result = array_map(fn (array $vertexes) => $this->createSubGraph($graph, $vertexes), $operations);

        return $result;
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
                    $connections[$vertexA][$vertexB] = $connections[$vertexB][$vertexA] = $graph->getValue($vertexA, $vertexB);
                }
            }
        }

        return new $graph($connections, $vertexes);
    }

    public function graphToDirection(Graph $graph): DirectionGraph
    {
        $connections = [];

        /**
         * @var int $vertexA
         * @var array<bool|int> $connection
         */
        foreach ($graph->getConnections() as $vertexA => $connection) {
            foreach ($connection as $vertexB => $value) {
                $connections[$vertexA][$vertexB] = (int) $value;
            }
        }

        return new DirectionGraph($connections, $graph->vertexes);
    }
}
