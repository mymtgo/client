<?php

namespace App\Enums;

enum SessionState: string
{
    case Authenticated = 'authenticated';
    case Unauthenticated = 'unauthenticated';
}
