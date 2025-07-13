<?php

namespace App\Enum;

enum UserRole: string
{
    case Client = 'client';
    case Teacher = 'teacher';
    case Admin = 'admin';
}