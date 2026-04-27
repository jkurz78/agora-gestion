<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DevisLigne extends Model
{
    use HasFactory;

    /**
     * DevisLigne n'a pas de timestamps — c'est une ligne de document,
     * pas une entité autonome.
     */
    public $timestamps = false;

    protected $fillable = [
        'devis_id',
        'ordre',
        'libelle',
        'prix_unitaire',
        'quantite',
        'montant',
        'sous_categorie_id',
    ];

    protected function casts(): array
    {
        return [
            'devis_id' => 'integer',
            'ordre' => 'integer',
            'prix_unitaire' => 'decimal:2',
            'quantite' => 'decimal:3',
            'montant' => 'decimal:2',
            'sous_categorie_id' => 'integer',
        ];
    }

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }
}
