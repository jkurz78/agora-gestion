<?php

declare(strict_types=1);

namespace App\Enums\Newsletter;

enum DesinscriptionAction: string
{
    case Optout = 'optout';
    case Deleted = 'deleted';
    case Noop = 'noop';
}
