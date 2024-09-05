<?php

declare(strict_types=1);

namespace EugeneErg\Graphs\Exceptions;

use Exception;

final class InvalidVertexValueException extends Exception
{
    public function __construct(string $message = 'Invalid vertex value.')
    {
        parent::__construct($message);
    }
}
