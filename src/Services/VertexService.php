<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\Services;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\ValueObjects\DirectionGraph;
use EugeneErg\Graphs\ValueObjects\Edge;
use EugeneErg\Graphs\ValueObjects\Tree;
use Exception;

readonly class VertexService
{
    public function __construct(private GraphService $graphService, private EdgeService $edgeService)
    {
    }

    /**
     * @param Edge[][] $vertexes
     * @return Edge[]
     */
    public function mergeTree(array $vertexes, DirectionGraph $connections, SliceAggregate $slice): array
    {
        if ($connections->vertexes === []) {
            return $vertexes[0];
        }

        /** @var Edge[][][] $edgeMap */
        $edgeMap = [];
        $lastNumber = 0;
        /** @var Edge[] $addToEdgeList */
        $addToEdgeList = [];

        foreach ($vertexes as $branch => $edges) {
            $edgeMap[$branch] = $this->addEdgeToMap(
                $edges,
                $connections->getConnection($branch),
                $lastNumber,
            );

            if (count($edges) === 1 && count($edges[0]->vertexes) > 2) {
                $addToEdgeList[] = $edges[0];
            }

            $lastNumber += count($edges);
        }

        $edgeLists = array_merge(...$vertexes);

        if ($addToEdgeList !== []) {
            array_push($edgeLists, ...$addToEdgeList);
        }

        $root = $slice->getKey($vertexes);
        $graph = $this->graphService->direct($connections, $root);
        $connections = $graph->getConnection($root) ?? [];

        while (null !== $branch = array_key_first($connections)) {
            $vertex = $connections[$branch];
            unset($connections[$branch]);
            $connections = array_replace($connections, $graph->getConnection($branch) ?? []);
            $edgeNumberA = $slice->getKey($edgeMap[$root][$vertex]);
            $edgeA = $edgeMap[$root][$vertex][$edgeNumberA];
            $edgeNumberB = $slice->getKey($edgeMap[$branch][$vertex]);
            $edgeB = $edgeMap[$branch][$vertex][$edgeNumberB];
            $countAIsW = count($edgeA->vertexes) === 2;
            $countBIsW = count($edgeB->vertexes) === 2;

            if ($countAIsW && $countBIsW) {
                $newEdge = $this->getEdgeFromWW($vertex, $edgeA, $edgeB);
                unset($edgeMap[$branch]);
                unset($edgeMap[$root]);
                unset($edgeLists[$edgeNumberB]);
                $edgeLists[$edgeNumberA] = $newEdge;
                $edgeLists[] = $newEdge;
                $edgeMap[$root] =  $this->addEdgeToMap([$edgeNumberA => $newEdge], $connections);
            } else {
                $newEdges = $countAIsW || $countBIsW
                    ? $this->getEdgesFromWV(
                        $vertex,
                        $countAIsW ? $edgeA : $edgeB,
                        $countAIsW ? $edgeB : $edgeA
                    )
                    : $this->getEdgesFromVV($vertex, $edgeA, $edgeB);
                $this->delEdgeFromMap([$edgeNumberA => $edgeA], $root, $edgeMap);
                $this->moveEdgeInMap($branch, $root, $edgeNumberB, $edgeMap);
                [$edgeLists[$edgeNumberA], $edgeLists[$edgeNumberB]] = $newEdges;
                $edgeMap[$root] = array_replace(
                    $edgeMap[$root] ?? [],
                    $this->addEdgeToMap([
                        $edgeNumberA => $newEdges[0],
                        $edgeNumberB => $newEdges[1],
                    ], $connections),
                );
            }
        }

        return array_values($edgeLists);
    }

    /**
     * @param Edge[] $edgeList
     * @param int[] $vertexes
     * @return Edge[][]
     */
    private function addEdgeToMap(array $edgeList, array $vertexes, int $offset = 0): array
    {
        $result = [];

        foreach ($edgeList as $edgeNumber => $subEdge) {
            $intersect = array_intersect($vertexes, $subEdge->vertexes);

            foreach ($intersect as $vertex) {
                $result[$vertex][$edgeNumber + $offset] = $subEdge;
            }
        }

        return $result;
    }

    private function getEdgeFromWW(int $vertex, Edge $edgeA, Edge $edgeB): Edge
    {
        $posA = array_search($vertex, $edgeA->vertexes, true);
        $posB = array_search($vertex, $edgeB->vertexes, true);
        $partA = $this->edgeService->getPartEdge($edgeA, $posA + 1, count($edgeA->vertexes) >> 1);
        $partB = $this->edgeService->getPartEdge($edgeB, $posB, (count($edgeB->vertexes) >> 1) + 1);

        for ($i = count($partA) - 1; $i >= 0; $i--) {
            $partB[] = $partA[$i];
        }

        return new Edge($partB);
    }

    /**
     * @return Edge[]
     */
    private function getEdgesFromWV(int $vertex, Edge $edgeA, Edge $edgeB): array
    {
        $posA = array_search($vertex, $edgeA->vertexes, true);
        $posB = array_search($vertex, $edgeB->vertexes, true);
        $partA = $this->edgeService->getPartEdge($edgeA, $posA + 1, count($edgeA->vertexes) >> 1);
        $partB = $this->edgeService->getPartEdge($edgeB, $posB, (count($edgeB->vertexes) >> 1) + 1);
        $partB2 = $this->edgeService->getPartEdge($edgeB, $posB, count($partB) - count($edgeB->vertexes) - 2);

        for ($i = count($partA) - 1; $i >= 0; $i--) {
            $partB2[] = $partB[] = $partA[$i];
        }

        return [new Edge($partB), new Edge($partB2)];
    }

    /**
     * @return Edge[]
     */
    private function getEdgesFromVV(int $vertex, Edge $edgeA, Edge $edgeB): array
    {
        $posA = array_search($vertex, $edgeA->vertexes, true);
        $posB = array_search($vertex, $edgeB->vertexes, true);
        $partA = $this->edgeService->getPartEdge($edgeA, $posA + 1, count($edgeA->vertexes) >> 1);
        $partB = $this->edgeService->getPartEdge($edgeB, $posB, (count($edgeB->vertexes) >> 1) + 1);
        $partB2 = $this->edgeService->getPartEdge($edgeB, $posB, count($partB) - count($edgeB->vertexes) - 2);
        $partA2 = $this->edgeService->getPartEdge($edgeA, $posA - 1, count($partA) - count($edgeA->vertexes));

        for ($i = count($partA) - 1; $i >= 0; $i--) {
            $partB[] = $partA[$i];
        }

        for ($i = count($partA2) - 1; $i >= 0; $i--) {
            $partB2[] = $partA2[$i];
        }

        return [new Edge($partB), new Edge($partB2)];
    }

    /**
     * @param Edge[] $edgeList
     * @param Edge[][][] $edgeMap
     */
    private function delEdgeFromMap(array $edgeList, int $branch, array &$edgeMap): void
    {
        foreach ($edgeList as $edgeNumber => $subEdge) {
            foreach ($subEdge->vertexes as $vertex) {
                unset($edgeMap[$branch][$vertex][$edgeNumber]);

                if (($edgeMap[$branch][$vertex] ?? null) === []) {
                    unset($edgeMap[$branch][$vertex]);
                }
            }
        }

        if ($edgeMap[$branch] === []) {
            unset($edgeMap[$branch]);
        }
    }

    /**
     * @param Edge[][][] $edgeMap
     */
    private function moveEdgeInMap(int $branch, int $root, int $edgeException, array &$edgeMap): void
    {
        foreach ($edgeMap[$branch] as $vertex => $edges) {
            foreach ($edges as $edgeNumber => $edge) {
                if ($edgeNumber !== $edgeException) {
                    $edgeMap[$root][$vertex][$edgeNumber] = $edge;
                    unset($edgeMap[$branch][$vertex][$edgeNumber]);
                }
            }

            if ($edgeMap[$branch][$vertex] === []) {
                unset($edgeMap[$branch][$vertex]);
            }
        }

        if ($edgeMap[$branch] === []) {
            unset($edgeMap[$branch]);
        }
    }
}