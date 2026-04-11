<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class RemiseBancaire extends Model
{
    use SoftDeletes;

    protected $table = 'remises_bancaires';

    protected $fillable = [
        'numero',
        'date',
        'mode_paiement',
        'compte_cible_id',
        'virement_id',
        'libelle',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mode_paiement' => ModePaiement::class,
            'numero' => 'integer',
            'compte_cible_id' => 'integer',
            'virement_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function compteCible(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_cible_id');
    }

    public function virement(): BelongsTo
    {
        return $this->belongsTo(VirementInterne::class, 'virement_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class, 'remise_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'remise_id');
    }

    /**
     * Transactions directement sélectionnées (pas issues du flux règlement).
     */
    public function transactionsDirectes(): HasMany
    {
        return $this->hasMany(Transaction::class, 'remise_id')->whereNull('reglement_id');
    }

    public function isVerrouillee(): bool
    {
        if ($this->virement_id === null) {
            return false;
        }

        return $this->virement->isLockedByRapprochement();
    }

    public function referencePrefix(): string
    {
        return $this->mode_paiement === ModePaiement::Cheque ? 'RBC' : 'RBE';
    }

    public function montantTotal(): float
    {
        return (float) $this->reglements()->sum('montant_prevu')
            + (float) $this->transactionsDirectes()->sum('montant_total');
    }
}
