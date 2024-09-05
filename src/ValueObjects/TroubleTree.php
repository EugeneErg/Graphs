<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final class TroubleTree
{
    use FindVertexTrait;

    public readonly int $level;

    /** @var TroubleTree[][] */
    public array $leftParents = [];

    /** @var TroubleTree[][] */
    public array $rightParents = [];

    /**
     * @param Edge[] $edges
     * @param int[] $vertexes
     */
    public function __construct(
        public Edge $edge,
        public array $vertexes,
        public array $edges = [],
        public readonly ?self $leftChild = null,
        public readonly ?self $rightChild = null,
    ) {
        if ($leftChild !== null) {
            $firstKey = array_key_first($vertexes);
            $leftChild->rightParents[$vertexes[$firstKey]][] = $this;
        }

        if ($rightChild !== null) {
            $lastKey = array_key_last($vertexes);
            $rightChild->leftParents[$vertexes[$lastKey]][] = $this;
        }

        $this->level = max($leftChild->level ?? -1, $rightChild->level ?? -1) + 1;
    }

    public function removeLeftParent(self $parent): void
    {
        foreach ($this->leftParents as $pos1 => $parents) {
            /** @var int|string|false $pos2 */
            $pos2 = array_search($parent, $parents, true);

            if ($pos2 !== false) {
                unset($this->leftParents[$pos1][$pos2]);
            }
        }
    }

    /**
     * @return int[]
     */
    public function findPath(int $vertex, bool $onRight): array
    {
        $tree = $this;
        $result = [];

        while ($tree !== null) {
            $pos = $tree->findVertexPosition($vertex);

            if ($pos === null) {
                throw new \RuntimeException('Path not found.');
            }

            $item = array_slice($tree->vertexes, $onRight ? $pos + 1 : 0, $onRight ? null : $pos);
            $onRight ? $result[] = $item : array_unshift($result, $item);
            $vertex = $tree->vertexes[$onRight ? count($tree->vertexes) - 1 : 0];
            $tree = $onRight ? $tree->rightChild : $tree->leftChild;
        }

        return array_merge(...$result);
    }
}
