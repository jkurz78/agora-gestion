<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenant\TransactionLigneTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TransactionLigne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transaction_lignes';

    public $timestamps = false;

    /**
     * Isolation tenant fail-closed dérivée de la transaction parente (audit #8).
     * Voir TransactionLigneTenantScope : pas de colonne association_id locale,
     * la transaction reste source unique de vérité.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(new TransactionLigneTenantScope);
    }

    protected $fillable = [
        'transaction_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
        'piece_jointe_path',
        'helloasso_item_id',
        'helloasso_option_id',
        'helloasso_tier_id',
        'helloasso_line_key',
        'helloasso_discount_code',
        // Partie double — ajoutés Step 10
        'compte_id',
        'debit',
        'credit',
        'tiers_id',
        'lettrage_code',
        'poste_tiers_parent_id',
        'libelle',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'transaction_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
            'helloasso_item_id' => 'integer',
            'helloasso_option_id' => 'integer',
            'helloasso_tier_id' => 'integer',
            // Partie double — ajoutés Step 10
            'compte_id' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'tiers_id' => 'integer',
            'poste_tiers_parent_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Lignes de ventilation métier (saisies par l'utilisateur).
     *
     * Filtre les lignes "PD-only" générées par EcritureGenerator (411/401/5XXX)
     * qui sont des écritures techniques du grand livre, invisibles dans les
     * écrans de saisie/édition côté utilisateur.
     *
     * Critère (DC-10a) : le compte pointé par `compte_id` est de classe 6 ou 7.
     * Les lignes PD-only pointent toujours vers des comptes système de classe
     * 4 (411/401) ou 5 (512X/5112/530) — jamais 6/7 — donc ce critère les
     * exclut par construction.
     */
    public function scopeVentilation(Builder $q): Builder
    {
        return $q->whereHas('compte', fn (Builder $q) => $q->whereIn('classe', [6, 7]));
    }

    /**
     * Ligne parente d'un item HelloAsso, OU ligne d'une transaction manuelle.
     *
     * Le prédicat doit couvrir les deux mondes : `helloasso_line_key` vaut 'parent'
     * sur une ligne HelloAsso, mais NULL sur une saisie manuelle. Filtrer sur la
     * seule valeur 'parent' ferait disparaître toutes les adhésions et tous les
     * reçus fiscaux saisis à la main.
     *
     * Le groupement en closure n'est pas cosmétique : un `orWhere` à plat
     * s'échapperait de la contrainte de transaction courante par précédence SQL,
     * et ramasserait les lignes parentes de TOUTES les transactions.
     */
    public function scopeLigneParenteOuManuelle(Builder $q): Builder
    {
        return $q->where(function (Builder $q): void {
            $q->whereNull('helloasso_item_id')
                ->orWhere('helloasso_line_key', 'parent');
        });
    }

    /**
     * Toutes les lignes sauf les lignes de remise HelloAsso.
     *
     * Une ligne de remise est une donnée de la plateforme, jamais une saisie : elle
     * est masquée du formulaire de transaction et exclue de ses contrôles de
     * cardinalité, sans quoi le nombre de lignes soumises ne pourrait jamais
     * égaler le nombre de lignes en base sur une transaction remisée.
     */
    public function scopeHorsRemiseHelloAsso(Builder $q): Builder
    {
        return $q->where(function (Builder $q): void {
            $q->whereNull('helloasso_line_key')
                ->orWhere('helloasso_line_key', '!=', 'discount');
        });
    }

    // -------------------------------------------------------------------------
    // Partie double — accesseurs et méthodes (Step 10)
    // -------------------------------------------------------------------------

    /**
     * Retourne true si la ligne est lettrée (lettrage_code IS NOT NULL).
     */
    public function isLettree(): bool
    {
        return $this->lettrage_code !== null;
    }

    /**
     * Retourne le montant signé : debit - credit.
     * Positif pour une écriture débit, négatif pour une écriture crédit.
     */
    protected function montantSigne(): Attribute
    {
        return Attribute::get(fn (): float => (float) $this->debit - (float) $this->credit);
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Compte PCG associé à cette ligne d'écriture (partie double, Step 10+).
     */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class);
    }

    /**
     * Tiers associé à cette ligne d'écriture (partie double, Step 10+).
     */
    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function posteTiersParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'poste_tiers_parent_id');
    }

    public function fractionsPosteTiers(): HasMany
    {
        return $this->hasMany(self::class, 'poste_tiers_parent_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(TransactionLigneAffectation::class);
    }

    public function recuFiscalActif(): HasOne
    {
        return $this->hasOne(RecuFiscalEmis::class, 'transaction_ligne_id')->whereNull('annule_at');
    }
}
