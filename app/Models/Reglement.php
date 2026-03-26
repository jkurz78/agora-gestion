<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Reglement extends Model
{
    protected $fillable = [
        'participant_id',
        'seance_id',
        'mode_paiement',
        'montant_prevu',
        'remise_id',
    ];

    protected function casts(): array
    {
        return [
            'mode_paiement' => ModePaiement::class,
            'montant_prevu' => 'decimal:2',
            'remise_id' => 'integer',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    public function remise(): BelongsTo
    {
        return $this->belongsTo(RemiseBancaire::class, 'remise_id');
    }
}
