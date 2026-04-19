<?php

declare(strict_types=1);

namespace App\Services\Portail;

enum RequestResult: string
{
    case Sent = 'sent';
    case Silent = 'silent'; // anti-énum : Tiers inconnu
    case TooSoon = 'too_soon'; // renvoi trop tôt
}
