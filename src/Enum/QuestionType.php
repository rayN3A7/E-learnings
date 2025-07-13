<?php

namespace App\Enum;

enum QuestionType: string
{
    case MCQ = 'MCQ';
    case Numeric = 'numeric';
}