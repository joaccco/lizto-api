<?php

namespace App\Domain\Works\Enums;

enum WorkStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case PendingCompletion = 'pending_completion';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
