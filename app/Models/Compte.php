<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal model for Step 5 of plans/fondations-partie-double-slice1.md.
 *
 * Only the columns, casts, and SoftDeletes needed for the ComptePolicy and
 * seeder tests are wired here. Full enrichment lands in Step 9.
 *
 * TODO step 9 — enrich with scopes (ofNumero, ofNumeroSysteme, lettrables, classe, bancaires)
 *               + relations (lignes()) + HasFactory.
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
    ];

    protected function casts(): array
    {
        return [
            'classe' => 'integer',
            'actif' => 'boolean',
            'est_systeme' => 'boolean',
            'pour_inscriptions' => 'boolean',
            'lettrable' => 'boolean',
            'solde_initial' => 'decimal:2',
            'date_solde_initial' => 'date',
        ];
    }
}
