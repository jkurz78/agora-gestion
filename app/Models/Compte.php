<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Enriched model for Step 9 of plans/fondations-partie-double-slice1.md.
 *
 * Extends the minimal Step-5 skeleton with static finders (ofNumero,
 * ofNumeroSysteme), query scopes (lettrables, classe, bancaires),
 * and the lignes() HasMany relation to TransactionLigne.
 *
 * The casts, fillable, table, SoftDeletes, and association() relation from
 * Step 5 are preserved untouched.
 */
final class Compte extends TenantModel
{
    use SoftDeletes;

    protected $table = 'comptes';

    protected $fillable = [
        'association_id',
        'numero_pcg',
        'intitule',
        'classe',
        'categorie_id',
        'parent_compte_id',
        'actif',
        'est_systeme',
        'pour_inscriptions',
        'lettrable',
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
        'compte_bancaire_id',
    ];

    protected function casts(): array
    {
        return [
            'classe' => 'integer',
            'compte_bancaire_id' => 'integer',
            'actif' => 'boolean',
            'est_systeme' => 'boolean',
            'pour_inscriptions' => 'boolean',
            'lettrable' => 'boolean',
            'solde_initial' => 'decimal:2',
            'date_solde_initial' => 'date',
        ];
    }

    // -------------------------------------------------------------------------
    // Static finders
    // -------------------------------------------------------------------------

    /**
     * Returns the compte matching the given numero_pcg for the current tenant,
     * or null if not found.
     */
    public static function ofNumero(string $numero): ?self
    {
        return self::where('numero_pcg', $numero)->first();
    }

    /**
     * Returns the system compte matching the given numero_pcg for the current
     * tenant. Throws ModelNotFoundException if missing — system accounts must
     * always exist post-migration.
     */
    public static function ofNumeroSysteme(string $numero): self
    {
        return self::where('numero_pcg', $numero)
            ->where('est_systeme', true)
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Filter to comptes that are lettrable (lettrable = TRUE).
     */
    public function scopeLettrables(Builder $q): Builder
    {
        return $q->where('lettrable', true);
    }

    /**
     * Filter to comptes belonging to the given PCG class.
     */
    public function scopeClasse(Builder $q, int $classe): Builder
    {
        return $q->where('classe', $classe);
    }

    /**
     * Filter to physical bank accounts (512x…) only.
     *
     * Uses LIKE '512_%' — one mandatory character after '512' plus any
     * optional suffix — to support tenants with 10+ banks (51210, 51211…).
     * This intentionally excludes 5112 (cheques) and 530 (caisse).
     * Consistent with Step 4 down() LIKE pattern.
     */
    public function scopeBancaires(Builder $q): Builder
    {
        return $q->where('classe', 5)
            ->where('numero_pcg', 'LIKE', '512_%');
    }

    // -------------------------------------------------------------------------
    // Prédicats métier
    // -------------------------------------------------------------------------

    /**
     * Returns true if this compte is a physical bank account (512X…).
     *
     * In-memory equivalent of scopeBancaires (LIKE '512_%') : classe 5 + numéro
     * commençant par '512' avec au moins un caractère après (5121, 51210…).
     * Exclut volontairement 5112 (chèques à encaisser) et 530 (caisse).
     * Source unique de la règle « 512X bancaire physique » côté instance —
     * évite la duplication du str_starts_with/strlen dans EcritureGenerator.
     */
    public function estBancaire(): bool
    {
        return $this->classe === 5
            && str_starts_with((string) $this->numero_pcg, '512')
            && strlen((string) $this->numero_pcg) > 3;
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The transaction lignes posted to this account.
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class, 'compte_id');
    }

    /**
     * The source bank account (comptes_bancaires) for a 512X physical bank
     * compte. NULL for non-bank comptes.
     */
    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_bancaire_id');
    }
}
