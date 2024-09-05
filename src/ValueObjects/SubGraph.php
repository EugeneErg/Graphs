<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final class SubGraph
{
    /**
     * @param Edge[] $edges
     */
    public function __construct(public Edge $counter, public array $edges = [])
    {
    }
}
