<?php

declare(strict_types=1);

namespace App\Services\Portail;

enum VerifyStatus: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case Cooldown = 'cooldown';
}
