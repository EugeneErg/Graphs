<?php

declare(strict_types = 1);

namespace EugeneErg\Graphs\ValueObjects;

enum IntersectType: string
{
    // Отрезок равен контуру
    case Equals = 'equals';
    // Отрезок поглотил контур
    case Absorption = 'absorption';
    // отрезок содержится в контуре
    case Contain = 'contain';
    // левая часть отрезка пересекается с контуром
    case LeftIntersect = 'left-intersect';
    //правая часть отрезка пересекается с контуром
    case RightIntersect = 'right-intersect';
    //обе части отрезка пересекаются с контуром
    case BothIntersect = 'both-intersect';
    //отрезок не пересекается с контуром
    case NotIntersect = 'not-intersect';
}