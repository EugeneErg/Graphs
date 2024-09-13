<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class GravityGroup implements GravityInterface
{
    /** @var GravityInterface[] */
    public array $gravities;

    public function __construct(GravityInterface ...$gravities)
    {
        $this->gravities = $gravities;
    }

    /** @return GravityInterface[] */
    public function getItems(): array
    {
        return $this->gravities;
    }
}
