<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Operation;

final class DepenseLigneAffectation extends Model
{
    protected $table = 'depense_ligne_affectations';

    protected $fillable = [
        'depense_ligne_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'depense_ligne_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function depenseLigne(): BelongsTo
    {
        return $this->belongsTo(DepenseLigne::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
