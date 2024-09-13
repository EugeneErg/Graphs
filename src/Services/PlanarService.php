<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Topology;
use EugeneErg\Graphs\ValueObjects\Tree;
use EugeneErg\Graphs\ValueObjects\TreeEdge;
use Exception;

readonly class PlanarService
{
    public function __construct(
        private GraphService $graphService,
        private TreeService $treeService,
        private EdgeService $edgeService,
        private VertexService $vertexService,
        private ArcService $arcService,
        private CoordinateService $coordinateService,
    ) {
    }

    /**
     * @param true[][] $connections
     *
     * @return Topology[]
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function connectionsToSwg(array $connections, SliceAggregate $sliceAggregate): array
    {
        $graph = $this->graphService->createFromConnections($connections);
        $disconnectedGraphs = $this->graphService->splitGraphOnDisconnected($graph);
        $trees = [];

        foreach ($disconnectedGraphs as $graph) {
            $trees[] = $this->treeService->fromConnectionGraph((new ArticulationVertexesAggregate($graph)));
        }

        $topologies = [];

        foreach ($trees as $treePos => $tree) {
            $edges = $this->treeToSwg($tree, $sliceAggregate);
            $outerKey = $sliceAggregate->getKey($edges);
            $outerEdge = $edges[$outerKey];
            unset($edges[$outerKey]);
            $topologies[$treePos] = $this->coordinateService->getCoordinates(new Topology($outerEdge, $this->arcService->createArcs($edges, $outerEdge)), 100);
        }

        return $topologies;
    }

    /**
     * @return Edge[]
     */
    private function edgeToList(TreeEdge $tree): array
    {
        $parents = [$tree];
        $result = [$tree->edge];

        for ($i = 0; $i < count($parents); $i++) {
            $parent = $parents[$i];

            foreach ($parent->children as $child) {
                $child->children === [] ? $result[] = $child->edge : $parents[] = $child;
            }
        }

        return $result;
    }

    /**
     * @return Edge[]
     *
     * @throws Exception
     */
    private function treeToSwg(Tree $tree, SliceAggregate $sliceAggregate): array
    {
        /** @var Edge[][] $edgesCube */
        $edgesCube = [];

        foreach ($tree->branches as $branchPos => $branch) {
            $edgesCube[$branchPos] = $this->edgeToList(
                $this->edgeService->splitOnTreeEdges($branch, $sliceAggregate),
            );
        }

        return $this->vertexService->mergeTree($edgesCube, $tree->connections, $sliceAggregate);
    }
}
