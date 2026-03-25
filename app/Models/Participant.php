<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Participant extends Model
{
    protected $fillable = [
        'tiers_id',
        'operation_id',
        'date_inscription',
        'est_helloasso',
        'helloasso_item_id',
        'helloasso_order_id',
        'notes',
        'refere_par_id',
    ];

    protected function casts(): array
    {
        return [
            'date_inscription' => 'date',
            'est_helloasso' => 'boolean',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function referePar(): BelongsTo
    {
        return $this->belongsTo(Tiers::class, 'refere_par_id');
    }

    public function donneesMedicales(): HasOne
    {
        return $this->hasOne(ParticipantDonneesMedicales::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class);
    }
}
