<?php

namespace App\Enum;

enum MediaType: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Video = 'video';
    case Other = 'other';
}