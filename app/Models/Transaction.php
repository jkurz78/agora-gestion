<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'date',
        'libelle',
        'montant_total',
        'mode_paiement',
        'tiers_id',
        'reference',
        'compte_id',
        'pointe',
        'notes',
        'saisi_par',
        'rapprochement_id',
        'remise_id',
        'reglement_id',
        'numero_piece',
        'helloasso_order_id',
        'helloasso_cashout_id',
        'helloasso_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeTransaction::class,
            'date' => 'date',
            'montant_total' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'pointe' => 'boolean',
            'tiers_id' => 'integer',
            'compte_id' => 'integer',
            'saisi_par' => 'integer',
            'rapprochement_id' => 'integer',
            'remise_id' => 'integer',
            'reglement_id' => 'integer',
            'helloasso_order_id' => 'integer',
            'helloasso_cashout_id' => 'integer',
            'helloasso_payment_id' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
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

    public function remise(): BelongsTo
    {
        return $this->belongsTo(RemiseBancaire::class, 'remise_id');
    }

    public function reglement(): BelongsTo
    {
        return $this->belongsTo(Reglement::class, 'reglement_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class);
    }

    public function montantSigne(): float
    {
        $montant = (float) $this->montant_total;

        return $this->type === TypeTransaction::Depense ? -$montant : $montant;
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
