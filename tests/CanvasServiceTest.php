<?php

declare(strict_types=1);

namespace Tests;

use EugeneErg\Graphs\Aggregates\Canvas;

/**
 * Холст — заливка по связям графа: ею разбираются связные куски и ветви.
 */
final class CanvasServiceTest extends AbstractTestCase
{
    public function testFillPaintsWholeConnectedComponent(): void
    {
        $canvas = new Canvas($this->getGraphService()->createFromConnections(self::getSimpleTriangle()));

        $painted = $this->getCanvasService()->fill($canvas, 0, 7);

        sort($painted);
        self::assertSame([0, 1, 2], $painted);
        self::assertTrue($canvas->isPixel(0, 7));
        self::assertTrue($canvas->isPixel(1, 7));
        self::assertTrue($canvas->isPixel(2, 7));
    }

    public function testFillStopsAtComponentBorder(): void
    {
        $connections = self::merge(false, self::getSimpleTriangle(), self::getSimpleTriangle());
        $canvas = new Canvas($this->getGraphService()->createFromConnections($connections));

        $painted = $this->getCanvasService()->fill($canvas, 0, 7);

        sort($painted);
        self::assertSame([0, 1, 2], $painted);
        self::assertSame(0, $canvas->getPixel(3), 'Второй кусок остаётся нетронутым.');
    }

    public function testFillDoesNotCrossAlreadyPaintedVertexes(): void
    {
        // Цепочка 0-1-2-3: закрашенная вершина 1 отрезает 2 и 3 от заливки.
        $canvas = new Canvas($this->getGraphService()->createFromConnections(self::getThreeLines()));
        $service = $this->getCanvasService();
        $service->setPixels($canvas, [1], 5);

        $painted = $service->fill($canvas, 0, 7);

        self::assertSame([0], $painted);
        self::assertSame(5, $canvas->getPixel(1));
        self::assertSame(0, $canvas->getPixel(2));
    }

    public function testSetPixelsPaintsOnlyListedVertexes(): void
    {
        $canvas = new Canvas($this->getGraphService()->createFromConnections(self::getSimpleTriangle()));

        $this->getCanvasService()->setPixels($canvas, [0, 2], 3);

        self::assertSame(3, $canvas->getPixel(0));
        self::assertSame(0, $canvas->getPixel(1));
        self::assertSame(3, $canvas->getPixel(2));
    }

    public function testUntouchedVertexIsZero(): void
    {
        $canvas = new Canvas($this->getGraphService()->createFromConnections(self::getSimpleTriangle()));

        self::assertSame(0, $canvas->getPixel(1));
        self::assertTrue($canvas->isPixel(1, 0));
    }
}
