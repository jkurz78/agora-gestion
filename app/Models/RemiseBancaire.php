<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

final class RemiseBancaire extends TenantModel
{
    use SoftDeletes;

    protected $table = 'remises_bancaires';

    protected $fillable = [
        'association_id',
        'numero',
        'date',
        'mode_paiement',
        'compte_cible_id',
        'libelle',
        'saisi_par',
        'comptabilisee_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mode_paiement' => ModePaiement::class,
            'numero' => 'integer',
            'compte_cible_id' => 'integer',
            'saisi_par' => 'integer',
            'comptabilisee_at' => 'datetime',
        ];
    }

    public function compteCible(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_cible_id');
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

    public function isVerrouillee(): bool
    {
        return $this->transactions()
            ->where('statut_reglement', StatutReglement::Pointe->value)
            ->exists();
    }

    public function referencePrefix(): string
    {
        return $this->mode_paiement === ModePaiement::Cheque ? 'RBC' : 'RBE';
    }

    public function montantTotal(): float
    {
        return (float) $this->transactions()->operationnel()->sum(DB::raw('ABS(montant_total)'));
    }
}
