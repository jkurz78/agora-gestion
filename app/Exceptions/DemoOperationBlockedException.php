<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DemoOperationBlockedException extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct("Opération « {$operation} » désactivée en démo.");
    }
}
