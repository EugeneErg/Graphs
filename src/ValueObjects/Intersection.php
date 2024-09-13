<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\ValueObjects;

final readonly class Intersection
{
    public bool $isOuter;

    /**
     * @param int[] $vertexes
     * @param bool[] $connections
     * @param bool|null $isOuter
     */
    public function __construct(public array $vertexes, public array $connections, ?bool $isOuter = null)
    {
        if ($isOuter !== null) {
            $this->setIsOuter($isOuter);
        }
    }

    public function setIsOuter(bool $isOuter): void
    {
        $this->isOuter = $isOuter; /** @phpstan-ignore-line */
    }
}
