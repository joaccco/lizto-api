<?php

namespace App\Infrastructure\Notifications\ValueObjects;

class FcmSendResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_INVALID_TOKEN = 'invalid_token';
    public const STATUS_TRANSIENT_ERROR = 'transient_error';
    public const STATUS_MISSING_CREDENTIALS = 'missing_credentials';

    public function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $errorMessage = null
    ) {}

    public static function success(?string $messageId = null): self
    {
        return new self(self::STATUS_SUCCESS, messageId: $messageId);
    }

    public static function invalidToken(?string $errorMessage = null): self
    {
        return new self(self::STATUS_INVALID_TOKEN, errorMessage: $errorMessage);
    }

    public static function transientError(?string $errorMessage = null): self
    {
        return new self(self::STATUS_TRANSIENT_ERROR, errorMessage: $errorMessage);
    }

    public static function missingCredentials(?string $errorMessage = null): self
    {
        return new self(self::STATUS_MISSING_CREDENTIALS, errorMessage: $errorMessage ?? 'FCM credentials missing or incomplete in configuration.');
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isInvalidToken(): bool
    {
        return $this->status === self::STATUS_INVALID_TOKEN;
    }

    public function isTransientError(): bool
    {
        return $this->status === self::STATUS_TRANSIENT_ERROR;
    }

    public function isMissingCredentials(): bool
    {
        return $this->status === self::STATUS_MISSING_CREDENTIALS;
    }
}
