<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class VirementInterne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'virements_internes';

    protected $fillable = [
        'date',
        'montant',
        'compte_source_id',
        'compte_destination_id',
        'reference',
        'notes',
        'saisi_par',
        'rapprochement_source_id',
        'rapprochement_destination_id',
        'numero_piece',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'montant' => 'decimal:2',
            'compte_source_id' => 'integer',
            'compte_destination_id' => 'integer',
            'saisi_par' => 'integer',
            'rapprochement_source_id' => 'integer',
            'rapprochement_destination_id' => 'integer',
        ];
    }

    public function compteSource(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_source_id');
    }

    public function compteDestination(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_destination_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function rapprochementSource(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_source_id');
    }

    public function rapprochementDestination(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_destination_id');
    }

    public function isLockedByRapprochement(): bool
    {
        $lockedBySource = $this->rapprochement_source_id !== null
            && $this->rapprochementSource?->isVerrouille() === true;
        $lockedByDestination = $this->rapprochement_destination_id !== null
            && $this->rapprochementDestination?->isVerrouille() === true;

        return $lockedBySource || $lockedByDestination;
    }

    /**
     * @param  Builder<VirementInterne>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
