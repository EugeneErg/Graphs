<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\ArticulationVertexesAggregate;
use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Exceptions\InvalidConnectionException;
use EugeneErg\Graphs\Exceptions\InvalidVertexValueException;
use EugeneErg\Graphs\ValueObjects\Arc;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Point2D;
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
     * @return Point2D[][]
     *
     * @throws InvalidConnectionException
     * @throws InvalidVertexValueException
     * @throws Exception
     */
    public function connectionsToSvg(array $connections, SliceAggregate $sliceAggregate): array
    {
        $graph = $this->graphService->createFromConnections($connections);

        //$this->graphService->compress($graph); вероятно сжимать двусвязные группы вершин все же не нужно,
        // так как потом нужно искать расстояние между ними

        $disconnectedGraphs = $this->graphService->splitGraphOnDisconnected($graph);

        $trees = [];

        foreach ($disconnectedGraphs as $graph) {
            $trees[] = $this->treeService->fromConnectionGraph((new ArticulationVertexesAggregate($graph)));
        }

        $coordinates = [];

        foreach ($trees as $treePos => $tree) {
            $edges = $this->treeToSvg($tree, $sliceAggregate);
            $outerKey = $sliceAggregate->getKey($edges);
            $outerEdge = $edges[$outerKey];
            unset($edges[$outerKey]);

            $arcs = $this->arcService->createArcs($edges, $outerEdge);

            $treeCoordinate = $this->coordinateService->getCoordinates(new Topology($outerEdge, $arcs), 100);

            $coordinates[$treePos] = $this->coordinateService->relaxCoordinates($outerEdge, $edges, $treeCoordinate);

            file_put_contents(
                'test ' . $treePos . '.svg',
                $this->getSvgContent($coordinates[$treePos], $connections, 100),
            );
        }

        return $coordinates;
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
    private function treeToSvg(Tree $tree, SliceAggregate $sliceAggregate): array
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

    /**
     * @param Point2D[] $coordinates
     * @param true[][] $connections
     */
    private function getSvgContent(array $coordinates, array $connections, int $graphRadius): string
    {
        ob_start();

        require __DIR__ . '/../Views/graph-svg.php';

        return ob_get_clean();
    }
}
