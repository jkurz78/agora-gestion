<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeLigneFacture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FactureLigne extends Model
{
    protected $table = 'facture_lignes';

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'transaction_ligne_id', 'type', 'libelle', 'montant', 'ordre',
        'prix_unitaire', 'quantite', 'sous_categorie_id', 'operation_id', 'seance',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeLigneFacture::class,
            'montant' => 'decimal:2',
            'ordre' => 'integer',
            'facture_id' => 'integer',
            'transaction_ligne_id' => 'integer',
            'prix_unitaire' => 'decimal:2',
            'quantite' => 'decimal:3',
            'sous_categorie_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function transactionLigne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
