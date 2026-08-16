<?php

namespace App\Domain\Clarification\Enums;

enum QuestionnaireVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
