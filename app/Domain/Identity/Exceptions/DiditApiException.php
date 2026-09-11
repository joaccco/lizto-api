<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

class DiditApiException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $statusCode = 0,
        protected string $responseBody = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
