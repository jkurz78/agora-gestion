<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

final class TiersPortailOtp extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'email',
        'code_hash',
        'expires_at',
        'last_sent_at',
        'consumed_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
