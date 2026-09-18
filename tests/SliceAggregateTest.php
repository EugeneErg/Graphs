<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\ValueObjects\ConstSlice;
use EugeneErg\Graphs\ValueObjects\RandomSlice;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;

/**
 * Срез решает, какая из возможных укладок строится: он выбирает позицию
 * на каждой развилке. Любой выбор обязан давать плоскую укладку.
 */
final class SliceAggregateTest extends AbstractTestCase
{
    public function testZeroSliceAlwaysTakesFirstValue(): void
    {
        $slice = new ZeroSlice();
        $aggregate = new SliceAggregate($slice);

        self::assertSame('a', $aggregate->getKey(['a' => 1, 'b' => 2, 'c' => 3]));
        self::assertSame(7, $aggregate->getKey([7 => 'x', 8 => 'y']));
        self::assertSame([0, 0], $slice->getPath());
    }

    public function testKeyOfEmptyListIsNull(): void
    {
        self::assertNull((new SliceAggregate(new ZeroSlice()))->getKey([]));
    }

    public function testConstSliceRepeatsGivenPath(): void
    {
        $aggregate = new SliceAggregate(new ConstSlice([2, 1]));

        self::assertSame('c', $aggregate->getKey(['a' => 1, 'b' => 2, 'c' => 3]));
        self::assertSame('b', $aggregate->getKey(['a' => 1, 'b' => 2, 'c' => 3]));
    }

    /**
     * Случайная позиция обязана оставаться в пределах списка,
     * иначе выбор уходит за его конец и ключа не находится.
     */
    public function testRandomSliceStaysInsideList(): void
    {
        $slice = new RandomSlice();
        $aggregate = new SliceAggregate($slice);
        $values = ['a' => 1, 'b' => 2, 'c' => 3];

        for ($i = 0; $i < 200; $i++) {
            self::assertContains($aggregate->getKey($values), ['a', 'b', 'c']);
        }
    }

    public function testRandomSliceRecordsReplayablePath(): void
    {
        $slice = new RandomSlice();
        $aggregate = new SliceAggregate($slice);
        $values = ['a' => 1, 'b' => 2, 'c' => 3];
        $keys = [];

        for ($i = 0; $i < 20; $i++) {
            $keys[] = $aggregate->getKey($values);
        }

        $replay = new SliceAggregate(new ConstSlice($slice->getPath()));
        $replayed = [];

        for ($i = 0; $i < 20; $i++) {
            $replayed[] = $replay->getKey($values);
        }

        self::assertSame($keys, $replayed);
    }

    /**
     * Любая случайно выбранная укладка остаётся плоской.
     */
    public function testEveryRandomLayoutIsPlanar(): void
    {
        $connections = self::getTriangleInTriangle();

        srand(20260918);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $coordinates = $this->getPlanarService()->connectionsToCoordinates(
                $connections,
                new SliceAggregate(new RandomSlice()),
            );

            self::assertSame(
                0,
                self::countCrossings($connections, $coordinates),
                sprintf('Попытка %d дала непланарную укладку.', $attempt),
            );
        }
    }
}
