<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Transaction extends TenantModel
{
    use HasFactory, SoftDeletes, TenantStorage;

    protected $fillable = [
        'association_id',
        'type',
        'date',
        'libelle',
        'montant_total',
        'mode_paiement',
        'tiers_id',
        'reference',
        'compte_id',
        'notes',
        'saisi_par',
        'rapprochement_id',
        'remise_id',
        'reglement_id',
        'numero_piece',
        'piece_jointe_path',
        'piece_jointe_nom',
        'piece_jointe_mime',
        'helloasso_order_id',
        'helloasso_cashout_id',
        'helloasso_payment_id',
        'statut_reglement',
        'extournee_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeTransaction::class,
            'date' => 'date',
            'montant_total' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'statut_reglement' => StatutReglement::class,
            'tiers_id' => 'integer',
            'compte_id' => 'integer',
            'saisi_par' => 'integer',
            'rapprochement_id' => 'integer',
            'remise_id' => 'integer',
            'reglement_id' => 'integer',
            'helloasso_order_id' => 'integer',
            'helloasso_cashout_id' => 'integer',
            'helloasso_payment_id' => 'integer',
            'extournee_at' => 'datetime',
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

    public function noteDeFrais(): HasOne
    {
        return $this->hasOne(NoteDeFrais::class);
    }

    public function montantSigne(): float
    {
        $montant = (float) $this->montant_total;

        return $this->type === TypeTransaction::Depense ? -$montant : $montant;
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->statut_reglement === StatutReglement::Pointe;
    }

    public function isLockedByRemise(): bool
    {
        return $this->remise_id !== null;
    }

    public function factures(): BelongsToMany
    {
        return $this->belongsToMany(Facture::class, 'facture_transaction');
    }

    public function isLockedByFacture(): bool
    {
        return $this->factures()
            ->where('statut', StatutFacture::Validee)
            ->exists();
    }

    public function hasPieceJointe(): bool
    {
        return $this->piece_jointe_path !== null;
    }

    public function pieceJointeFullPath(): ?string
    {
        return $this->piece_jointe_path
            ? $this->storagePath('transactions/'.$this->id.'/'.basename($this->piece_jointe_path))
            : null;
    }

    public function pieceJointeUrl(): ?string
    {
        if (! $this->hasPieceJointe()) {
            return null;
        }

        return route('transactions.piece-jointe', $this);
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

    /**
     * Exclut les transactions extournées (origines + miroirs) des sélecteurs de règlement.
     *
     * Invariant cross-S1/S2 : une transaction dont extournee_at est non nul (origine extournée)
     * OU dont l'ID figure dans extournes.transaction_extourne_id (miroir d'extourne) ne doit
     * jamais être proposée comme règlement rattachable à une facture brouillon.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeRattachableAFacture(Builder $query): Builder
    {
        return $query
            ->whereNull('extournee_at')
            ->whereNotIn('id', Extourne::query()->select('transaction_extourne_id'));
    }

    /**
     * Extourne entry where this Transaction is the origin (one-shot).
     */
    public function extourneeVers(): HasOne
    {
        return $this->hasOne(Extourne::class, 'transaction_origine_id');
    }

    /**
     * Extourne entry where this Transaction is the mirror.
     */
    public function extournePour(): HasOne
    {
        return $this->hasOne(Extourne::class, 'transaction_extourne_id');
    }

    /**
     * True when this Transaction is itself an extourne (the mirror side).
     */
    protected function estUneExtourne(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->extournePour()->exists(),
        );
    }

    /**
     * Returns true when the user can extourne this transaction.
     *
     * Guards (must all hold) :
     *  - sens recette OU dépense — l'extourne est PCG-symétrique
     *    (amendement spec S1 — limitation MVP recette levée)
     *  - non encore extournée (extournee_at null)
     *  - n'est pas elle-même une extourne
     *  - non issue de HelloAsso
     *  - non portée par une facture validée
     *  - non soft-deleted
     */
    public function isExtournable(): bool
    {
        if ($this->extournee_at !== null) {
            return false;
        }

        if ($this->estUneExtourne) {
            return false;
        }

        if ($this->helloasso_order_id !== null) {
            return false;
        }

        if ($this->trashed()) {
            return false;
        }

        if ($this->isLockedByFacture()) {
            return false;
        }

        return true;
    }
}
