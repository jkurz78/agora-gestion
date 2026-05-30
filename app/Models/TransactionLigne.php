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
        'sous_categorie_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
        'piece_jointe_path',
        'helloasso_item_id',
        'helloasso_option_id',
        'helloasso_tier_id',
        // Partie double — ajoutés Step 10
        'compte_id',
        'debit',
        'credit',
        'tiers_id',
        'lettrage_code',
        'libelle',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'transaction_id' => 'integer',
            'sous_categorie_id' => 'integer',
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
     * Critère actuel : `sous_categorie_id NOT NULL` (les lignes legacy en ont,
     * les PD-only n'en ont pas). Au Step 40 (drop colonne legacy), basculer
     * sur `whereHas('compte', fn ($q) => $q->whereIn('classe', [6, 7]))`.
     */
    public function scopeVentilation(Builder $q): Builder
    {
        return $q->whereNotNull('sous_categorie_id');
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

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
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

    public function affectations(): HasMany
    {
        return $this->hasMany(TransactionLigneAffectation::class);
    }

    public function recuFiscalActif(): HasOne
    {
        return $this->hasOne(RecuFiscalEmis::class, 'transaction_ligne_id')->whereNull('annule_at');
    }
}
