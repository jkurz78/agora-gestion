<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Seance extends Model
{
    protected $fillable = [
        'operation_id',
        'numero',
        'date',
        'titre',
    ];

    protected function casts(): array
    {
        return [
            'operation_id' => 'integer',
            'date' => 'date',
            'numero' => 'integer',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }
}
