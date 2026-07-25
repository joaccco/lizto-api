<?php

namespace App\Domain\Survey\Enums;

enum QuestionInputType: string
{
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case Text = 'text';
    case Boolean = 'boolean';
    case Photo = 'photo';
}
