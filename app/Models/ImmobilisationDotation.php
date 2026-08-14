<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImmobilisationDotation extends TenantModel
{
    use HasFactory;

    protected $table = 'immobilisation_dotations';

    protected $fillable = [
        'association_id',
        'immobilisation_id',
        'exercice',
        'montant',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'exercice' => 'integer',
            'montant' => 'decimal:2',
        ];
    }

    public function immobilisation(): BelongsTo
    {
        return $this->belongsTo(Immobilisation::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
