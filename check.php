<?php

require_once 'src/ValueObjects/Angle.php';

use EugeneErg\Graphs\ValueObjects\Angle;

$angle1L = new Angle(90);
$angle1R = new Angle(45);
$angle2L = new Angle(180);
$angle2R = new Angle(0);

$angles = [$angle1L, $angle1R, $angle2L, $angle2R];
//$case = array_map(static fn (Angle $angle) => $angle->getRadian(), $angles);
uasort($angles, static fn (Angle $angleA, Angle $angleB) => $angleA <=> $angleB);


$prev = null;
$result = [];

foreach ($angles as $key => $value) {
    $case = 1 << $key;
    $prev === null || !$value->isEqual($prev)
        ? $result[] = $case
        : $result[count($result) - 1] += $case;
    $prev = $value;
}


var_dump($result);die;