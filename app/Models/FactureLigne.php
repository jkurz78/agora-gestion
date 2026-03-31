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
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeLigneFacture::class,
            'montant' => 'decimal:2',
            'ordre' => 'integer',
            'facture_id' => 'integer',
            'transaction_ligne_id' => 'integer',
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
}
