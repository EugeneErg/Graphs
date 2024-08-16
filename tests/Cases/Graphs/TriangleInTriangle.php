<?php

declare(strict_types = 1);

return [
    // Внешний треугольник
    0 => [1 => true, 2 => true, 3 => true],
    1 => [0 => true, 2 => true, 4 => true],
    2 => [0 => true, 1 => true, 5 => true],

    // Внутренний треугольник
    3 => [4 => true, 5 => true, 0 => true],
    4 => [3 => true, 5 => true, 1 => true],
    5 => [3 => true, 4 => true, 2 => true],
];

