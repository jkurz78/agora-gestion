<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Presence extends Model
{
    protected $fillable = [
        'seance_id',
        'participant_id',
        'statut',
        'kine',
        'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'statut' => 'encrypted',
            'kine' => 'encrypted',
            'commentaire' => 'encrypted',
        ];
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
