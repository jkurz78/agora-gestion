<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Adhesion extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'association_id',
        'tiers_id',
        'exercice',
        'transaction_id',
        'formule_adhesion_id',
        'date_debut',
        'date_fin',
        'notes',
        'saisi_par',
        'montant_facial',
        'deductible_fiscal',
        'mode',
        'duree_mois',
        'label_formule',
    ];

    protected $casts = [
        'exercice' => 'integer',
        'transaction_id' => 'integer',
        'formule_adhesion_id' => 'integer',
        'date_debut' => 'date',
        'date_fin' => 'date',
        'saisi_par' => 'integer',
        'montant_facial' => 'decimal:2',
        'deductible_fiscal' => 'boolean',
        'duree_mois' => 'integer',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function formuleAdhesion(): BelongsTo
    {
        return $this->belongsTo(FormuleAdhesion::class, 'formule_adhesion_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function scopeForExercice(Builder $query, int $annee): Builder
    {
        return $query->where('exercice', $annee);
    }

    /**
     * Une adhésion est "gratuite" / "offerte" lorsqu'elle n'est pas adossée à
     * une transaction (la transaction porte le paiement). Slice 3 : la colonne
     * dédiée a été supprimée, on dérive l'état du transaction_id.
     */
    public function estGratuite(): bool
    {
        return $this->transaction_id === null;
    }

    public function isModeIllimite(): bool
    {
        return $this->mode === 'illimite';
    }

    public function isModeDuree(): bool
    {
        return $this->mode === 'duree';
    }

    public function isModeExercice(): bool
    {
        return $this->mode === 'exercice';
    }
}
