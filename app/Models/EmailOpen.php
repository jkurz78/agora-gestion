<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailOpen extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'email_log_id',
        'ip',
        'user_agent',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'email_log_id' => 'integer',
            'opened_at' => 'datetime',
        ];
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }
}
