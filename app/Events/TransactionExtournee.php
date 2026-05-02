<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Extourne;
use Illuminate\Foundation\Events\Dispatchable;

final class TransactionExtournee
{
    use Dispatchable;

    public function __construct(public readonly Extourne $extourne) {}
}
