<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoNotification extends Model
{
    protected $table = 'helloasso_notifications';

    public $timestamps = false;

    protected $fillable = [
        'association_id',
        'event_type',
        'libelle',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
