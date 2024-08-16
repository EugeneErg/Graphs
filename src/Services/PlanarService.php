<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;

readonly class PlanarService
{
    public function __construct(
        private GraphService $graphService,
        private TreeService $treeService,
    ) {
    }

    /**
     * @param true[][] $connections
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     */
    public function connectionsToSwg(array $connections)
    {
        $graph = $this->graphService->createFromConnections($connections);
        $disconnectedGraphs = $this->graphService->splitGraphOnDisconnected($graph);
        $trees = [];

        foreach ($disconnectedGraphs as $graph) {
            $trees[] = $this->treeService->fromConnectionGraph((new ArticulationVertexesAggregate($graph)));
        }


    }
}