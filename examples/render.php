<?php

declare(strict_types=1);

/**
 * Рисует граф из tests/Cases/Graphs в анимированный SVG.
 *
 * Использование:
 *   php examples/render.php Big1 > big1.svg
 *   php examples/render.php TriangleInTriangle > nested.svg
 */

use EugeneErg\Graphs\Aggregates\SliceAggregate;
use EugeneErg\Graphs\Services\ArcService;
use EugeneErg\Graphs\Services\CanvasService;
use EugeneErg\Graphs\Services\CoordinateService;
use EugeneErg\Graphs\Services\EdgeService;
use EugeneErg\Graphs\Services\GraphService;
use EugeneErg\Graphs\Services\IntersectionService;
use EugeneErg\Graphs\Services\PlanarService;
use EugeneErg\Graphs\Services\SvgService;
use EugeneErg\Graphs\Services\TreeService;
use EugeneErg\Graphs\Services\VertexService;
use EugeneErg\Graphs\ValueObjects\ZeroSlice;

require __DIR__ . '/../vendor/autoload.php';

$name = $argv[1] ?? 'Big1';
$file = __DIR__ . '/../tests/Cases/Graphs/' . $name . '.php';

if (! is_file($file)) {
    fwrite(STDERR, sprintf("Нет такого графа: %s\n", $name));

    exit(1);
}

/** @var true[][] $connections */
$connections = require $file;

$canvasService = new CanvasService();
$graphService = new GraphService($canvasService);
$intersectionService = new IntersectionService($canvasService);
$edgeService = new EdgeService($canvasService, $intersectionService, $graphService);

$planarService = new PlanarService(
    $graphService,
    new TreeService($canvasService, $graphService),
    $edgeService,
    new VertexService($graphService, $edgeService),
    new ArcService(),
    new CoordinateService(),
    new SvgService(),
);

echo $planarService->connectionsToSvg($connections, new SliceAggregate(new ZeroSlice()));
