<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Cotisation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'membre_id',
        'exercice',
        'montant',
        'date_paiement',
        'mode_paiement',
        'compte_id',
        'pointe',
        'rapprochement_id',
        'numero_piece',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'date',
            'mode_paiement' => ModePaiement::class,
            'pointe' => 'boolean',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(Membre::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function rapprochement(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    /**
     * @param  Builder<Cotisation>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }
}
