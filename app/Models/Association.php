<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Association extends Model
{
    protected $table = 'association';

    protected $fillable = [
        'nom',
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
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'nom' => 'string',
            'adresse' => 'string',
            'code_postal' => 'string',
            'ville' => 'string',
            'email' => 'string',
            'telephone' => 'string',
            'logo_path' => 'string',
            'cachet_signature_path' => 'string',
            'facture_compte_bancaire_id' => 'integer',
            'anthropic_api_key' => 'encrypted',
        ];
    }
}
