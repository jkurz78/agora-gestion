<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Association extends Model
{
    use HasFactory;

    protected $table = 'association';

    protected $fillable = [
        'nom',
        'slug',
        'adresse',
        'code_postal',
        'ville',
        'email',
        'telephone',
        'logo_path',
        'cachet_signature_path',
        'siret',
        'forme_juridique',
        'facture_conditions_reglement',
        'facture_mentions_legales',
        'facture_mentions_penalites',
        'facture_compte_bancaire_id',
        'anthropic_api_key',
        'email_from',
        'email_from_name',
        'exercice_mois_debut',
        'statut',
        'wizard_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'nom' => 'string',
            'slug' => 'string',
            'adresse' => 'string',
            'code_postal' => 'string',
            'ville' => 'string',
            'email' => 'string',
            'telephone' => 'string',
            'logo_path' => 'string',
            'cachet_signature_path' => 'string',
            'facture_compte_bancaire_id' => 'integer',
            'anthropic_api_key' => 'encrypted',
            'email_from' => 'string',
            'email_from_name' => 'string',
            'exercice_mois_debut' => 'integer',
            'statut' => 'string',
            'wizard_completed_at' => 'datetime',
        ];
    }
}
