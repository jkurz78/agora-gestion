<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleSysteme: string
{
    case User = 'user';
    case SuperAdmin = 'super_admin';
}
