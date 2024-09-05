<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final class Trouble
{
    use FindVertexTrait;

    /** @var Edge[] */
    public array $edges = [];

    /** @var int[] */
    public array $firstVertexes;

    /** @var TroubleTree[] */
    public array $trees;

    public TroubleTree $mainTree;

    /**
     * @param int[] $vertexes
     */
    public function __construct(
        public array $vertexes,
        public readonly int $fromVertex,
        public readonly int $toVertex,
    ) {
    }
}
