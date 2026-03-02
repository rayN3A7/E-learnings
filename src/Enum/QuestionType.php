<?php

namespace App\Enum;

enum QuestionType: string
{
    case MCQ = 'mcq';
    case Numeric = 'numeric';
    case Text = 'text';
}