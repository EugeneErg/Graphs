<?php

declare(strict_types=1);

use EugeneErg\Graphs\ValueObjects\Point2D;

/**
 * @var Point2D[] $coordinates
 * @var true[][] $connections
 */
$vertexRadius = 6;
$vertexBorderSize = 1;
?>
<svg version="1.1"
     width="<?= $graphRadius * 2 + $vertexRadius + $vertexBorderSize ?>"
     height="<?= $graphRadius * 2 + $vertexRadius + $vertexBorderSize ?>"
     stroke="black"
     stroke-width="1"
     vector-effect="non-scaling-stroke"
     fill="white"
     viewBox="
        -<?= $graphRadius + $vertexRadius + $vertexBorderSize ?>
        -<?= $graphRadius + $vertexRadius + $vertexBorderSize?>
        <?= ($graphRadius + $vertexRadius + $vertexBorderSize) * 2 ?>
        <?= ($graphRadius + $vertexRadius + $vertexBorderSize) * 2 ?>"
     xmlns="http://www.w3.org/2000/svg">
    <?php foreach ($coordinates as $vertex => $position): ?>
        <symbol id="vertex<?= $vertex ?>"
                width="<?= $vertexRadius * 2 ?>"
                height="<?= $vertexRadius * 2 ?>"
                viewBox="0 0 <?= $vertexRadius * 2 ?> <?= $vertexRadius * 2 ?>">
            <circle
                vector-effect="non-scaling-stroke"
                cx="<?= $vertexRadius ?>"
                cy="<?= $vertexRadius ?>"
                r="<?= $vertexRadius ?>"/>
            <svg width="<?= $vertexRadius * 2 ?>"
                 height="<?= $vertexRadius * 2 ?>"
                 viewBox="-<?= strlen((string) $vertex) * 6 ?> -12 <?= strlen((string) $vertex) * 12 ?> 24">
                <text dominant-baseline="central"
                      text-anchor="middle"
                      font-size="20"
                      font-family="monospace"
                      vector-effect="non-scaling-stroke">
                    <?= $vertex ?>
                </text>
            </svg>
        </symbol>
    <?php endforeach ?>
    <?php foreach ($connections as $vertexA => $connection): ?>
        <?php foreach ($connection as $vertexB => $value): ?>
            <line
                x1="<?= $coordinates[$vertexA]->x ?>"
                y1="<?= $coordinates[$vertexA]->y ?>"
                x2="<?= $coordinates[$vertexB]->x ?>"
                y2="<?= $coordinates[$vertexB]->y ?>"
            />
        <?php endforeach ?>
    <?php endforeach ?>
    <?php foreach ($coordinates as $vertex => $position): ?>
        <use href="#vertex<?= $vertex ?>"
             x="<?= $position->x?>"
             y="<?= $position->y?>"
             style="
                 transform: translate(-<?= $vertexRadius ?>px, -<?= $vertexRadius ?>px);
             "
        />
    <?php endforeach ?>
</svg>