<?php

namespace App\Domain\Offers\Exceptions;

use Exception;

class MaxCounterOfferRoundsExceededException extends Exception
{
    public function __construct(string $message = "Se ha alcanzado el límite máximo de rondas de negociación para esta oferta.")
    {
        parent::__construct($message, 422);
    }

    public function render($request)
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 422);
    }
}
