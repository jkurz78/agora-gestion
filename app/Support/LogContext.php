<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class LogContext
{
    public static function boot(int $associationId, ?int $userId): void
    {
        Log::withContext([
            'association_id' => $associationId,
            'user_id' => $userId,
        ]);
    }
}
