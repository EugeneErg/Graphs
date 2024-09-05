<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Edge;
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
    ) {
    }

    /**
     * @param true[][] $connections
     *
     * @return Edge[][]
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

        $edgesByTree = [];

        foreach ($trees as $treePos => $tree) {
            $edgesByTree[$treePos] = $this->treeToSwg($tree, $sliceAggregate);
        }

        return $edgesByTree;
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
