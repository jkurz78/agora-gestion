<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SuperAdminAccessLog extends Model
{
    use HasFactory;

    protected $table = 'super_admin_access_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'association_id',
        'action',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
