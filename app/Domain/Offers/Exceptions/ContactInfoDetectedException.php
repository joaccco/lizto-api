<?php

namespace App\Domain\Offers\Exceptions;

use Exception;

class ContactInfoDetectedException extends Exception
{
    public function __construct(string $message = "No se permite compartir datos de contacto (teléfono, WhatsApp, email) antes de la contratación.")
    {
        parent::__construct($message, 422);
    }
}
