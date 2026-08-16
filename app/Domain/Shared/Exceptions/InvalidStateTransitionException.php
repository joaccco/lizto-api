<?php

namespace App\Domain\Shared\Exceptions;

use Exception;

class InvalidStateTransitionException extends Exception
{
    public function __construct(string $message = 'Transición de estado inválida.')
    {
        parent::__construct($message);
    }
}
