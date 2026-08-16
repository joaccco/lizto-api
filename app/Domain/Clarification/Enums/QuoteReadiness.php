<?php

namespace App\Domain\Clarification\Enums;

enum QuoteReadiness: string
{
    case InsufficientInformation = 'insufficient_information';
    case ReadyForMatch = 'ready_for_match';
    case ReadyForQuote = 'ready_for_quote';
    case RequiresProfessionalEvaluation = 'requires_professional_evaluation';
}
