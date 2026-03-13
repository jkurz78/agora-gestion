<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Recette extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'date',
        'libelle',
        'montant_total',
        'mode_paiement',
        'tiers',
        'reference',
        'compte_id',
        'pointe',
        'notes',
        'saisi_par',
        'rapprochement_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant_total' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'pointe' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
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

    public function lignes(): HasMany
    {
        return $this->hasMany(RecetteLigne::class);
    }

    /**
     * @param  Builder<Recette>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
