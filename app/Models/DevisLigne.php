<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeLigneDevis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DevisLigne extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'devis_id',
        'ordre',
        'type',
        'libelle',
        'prix_unitaire',
        'quantite',
        'montant',
        'compte_id',
    ];

    protected function casts(): array
    {
        return [
            'devis_id' => 'integer',
            'ordre' => 'integer',
            'type' => TypeLigneDevis::class,
            'prix_unitaire' => 'decimal:2',
            'quantite' => 'decimal:3',
            'montant' => 'decimal:2',
            'compte_id' => 'integer',
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

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }
}
