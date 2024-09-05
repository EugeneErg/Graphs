<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

enum SolutionType: string
{
    case Embedding = 'embedding';
    case Absorption = 'absorption';
    case Circle = 'circle';
}
