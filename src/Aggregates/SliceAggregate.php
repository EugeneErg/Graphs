<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\SliceInterface;

final readonly class SliceAggregate
{
    public function __construct(private SliceInterface $slice)
    {
    }

    /**
     * @param mixed[] $values
     */
    public function getKey(array $values): string|int|null
    {
        $position = $this->slice->getNextValue(count($values));
        $keyValue = array_slice($values, $position, 1, true);

        return array_key_first($keyValue);
    }
}
