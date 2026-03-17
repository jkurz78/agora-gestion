<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Operation;

final class RecetteLigneAffectation extends Model
{
    protected $table = 'recette_ligne_affectations';

    protected $fillable = [
        'recette_ligne_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'recette_ligne_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function recetteLigne(): BelongsTo
    {
        return $this->belongsTo(RecetteLigne::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
