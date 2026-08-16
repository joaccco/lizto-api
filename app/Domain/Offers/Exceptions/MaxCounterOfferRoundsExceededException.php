<?php

namespace App\Domain\Offers\Exceptions;

use Exception;

class MaxCounterOfferRoundsExceededException extends Exception
{
    public function __construct(string $message = "Se ha alcanzado el límite máximo de rondas de contraoferta. Debe aceptar o rechazar.")
    {
        parent::__construct($message, 422);
    }
}
