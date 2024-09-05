<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Solution
{
    public ?int $fromPosition;

    public ?int $toPosition;

    public SolutionType $type;

    public function __construct(public Trouble $trouble, public int $fromVertex, public int $toVertex)
    {
        $this->fromPosition = $this->trouble->findVertexPosition($fromVertex);
        $this->toPosition = $this->trouble->findVertexPosition($toVertex);
        $this->type = $this->calculateType();
    }

    private function calculateType(): SolutionType
    {
        if ($this->fromPosition === null || $this->toPosition === null) {
            return SolutionType::Absorption;
        }

        if ($this->fromPosition > $this->toPosition) {
            return SolutionType::Circle;
        }

        return SolutionType::Embedding;
    }
}
