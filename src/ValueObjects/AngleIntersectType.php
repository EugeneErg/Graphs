<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

enum AngleIntersectType: string
{
    case EqualLeft = 'equal_left';
    case EqualRight = 'equal_right';
    case Between = 'between';
    case Outside = 'outside';

    public function getMirror(): self
    {
        return match ($this) {
            self::EqualLeft => self::EqualRight,
            self::EqualRight => self::EqualLeft,
            default => $this,
        };
    }
}