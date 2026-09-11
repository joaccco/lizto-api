<?php

namespace App\Domain\Identity\DTOs;

class VerificationResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $identityId = null,
        public readonly ?string $diditSessionId = null,
        public readonly ?string $rejectionReason = null,
        public readonly ?array $renaperData = null,
        public readonly array $errors = [],
    ) {}

    public static function approved(int $identityId, ?string $diditSessionId = null, ?array $renaperData = null): self
    {
        return new self(
            status: 'approved',
            identityId: $identityId,
            diditSessionId: $diditSessionId,
            renaperData: $renaperData,
        );
    }

    public static function rejected(string $reason, ?string $diditSessionId = null, array $errors = []): self
    {
        return new self(
            status: 'rejected',
            diditSessionId: $diditSessionId,
            rejectionReason: $reason,
            errors: $errors,
        );
    }

    public static function pending(?string $diditSessionId = null): self
    {
        return new self(
            status: 'pending',
            diditSessionId: $diditSessionId,
        );
    }

    public static function failed(string $reason, array $errors = []): self
    {
        return new self(
            status: 'failed',
            rejectionReason: $reason,
            errors: $errors,
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'approved';
    }
}
