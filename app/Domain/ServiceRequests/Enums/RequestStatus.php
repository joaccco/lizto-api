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
}
