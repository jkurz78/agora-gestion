<?php

declare(strict_types=1);

namespace App\Enums\Newsletter;

enum SubscriptionRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
