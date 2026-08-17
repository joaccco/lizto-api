<?php

namespace App\Domain\Works\Enums;

enum WorkStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case PendingDiagnosisQuote = 'pending_diagnosis_quote';
    case InProgress = 'in_progress';
    case PendingCompletion = 'pending_completion';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingConfirmation => [self::Confirmed, self::PendingDiagnosisQuote, self::Cancelled, self::NoShow],
            self::Confirmed => [self::InProgress, self::PendingDiagnosisQuote, self::Completed, self::Cancelled, self::NoShow],
            self::PendingDiagnosisQuote => [self::Confirmed, self::InProgress, self::Cancelled],
            self::InProgress => [self::PendingCompletion, self::Completed, self::Disputed, self::Cancelled],
            self::PendingCompletion => [self::Completed, self::Disputed, self::Cancelled],
            self::Disputed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
