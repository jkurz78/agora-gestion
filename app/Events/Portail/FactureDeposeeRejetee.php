<?php

declare(strict_types=1);

namespace App\Events\Portail;

use App\Models\FacturePartenaireDeposee;
use Illuminate\Foundation\Events\Dispatchable;

final class FactureDeposeeRejetee
{
    use Dispatchable;

    public function __construct(public readonly FacturePartenaireDeposee $depot) {}
}
