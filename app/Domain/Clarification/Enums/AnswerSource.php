<?php

namespace App\Domain\Clarification\Enums;

enum AnswerSource: string
{
    case User = 'user';
    case AiExtracted = 'ai_extracted';
    case Profile = 'profile';
    case System = 'system';
    case Professional = 'professional';
}
