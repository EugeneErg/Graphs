<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Aggregates;

use EugeneErg\Graphs\ValueObjects\Stage;
use EugeneErg\Graphs\ValueObjects\StageKind;

/**
 * Журнал шагов алгоритма.
 *
 * Сервисы дописывают сюда то, что сделали, а отрисовка превращает журнал
 * в понятную последовательность сцен. Журнал необязателен: без него алгоритм
 * работает ровно так же и ничего не записывает.
 */
final class Trace
{
    /** @var Stage[] */
    private array $stages = [];

    /**
     * @param array<int, int[]> $groups
     * @param int[] $highlight
     */
    public function add(StageKind $kind, string $caption, array $groups = [], array $highlight = []): void
    {
        $this->stages[] = new Stage($kind, $caption, $groups, $highlight);
    }

    /**
     * @return Stage[]
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    public function isEmpty(): bool
    {
        return $this->stages === [];
    }
}
