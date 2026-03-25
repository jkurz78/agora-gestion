<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParticipantDonneesMedicales extends Model
{
    protected $table = 'participant_donnees_medicales';

    protected $fillable = [
        'participant_id',
        'date_naissance',
        'sexe',
        'poids',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'encrypted',
            'sexe' => 'encrypted',
            'poids' => 'encrypted',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
