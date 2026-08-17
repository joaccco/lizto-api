<?php

namespace App\Domain\ServiceRequests\Enums;

enum RequestStatus: string
{
    case PendingSurvey = 'pending_survey';
    case PendingMatching = 'pending_matching';
    case MatchingActive = 'matching_active';
    case ProviderSelected = 'provider_selected';
    case PendingProvider = 'pending_provider';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingSurvey => [self::PendingMatching, self::MatchingActive, self::ProviderSelected, self::Active, self::Cancelled, self::Expired],
            self::PendingMatching => [self::MatchingActive, self::ProviderSelected, self::Active, self::Cancelled, self::Expired],
            self::MatchingActive => [self::ProviderSelected, self::Active, self::Cancelled, self::Expired],
            self::ProviderSelected => [self::PendingProvider, self::MatchingActive, self::Active, self::Completed, self::Cancelled, self::Expired],
            self::PendingProvider => [self::Active, self::Completed, self::MatchingActive, self::Cancelled, self::Expired],
            self::Active => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
