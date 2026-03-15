<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class RecetteLigne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'recette_lignes';

    public $timestamps = false;

    protected $fillable = [
        'recette_id',
        'sous_categorie_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'recette_id' => 'integer',
            'sous_categorie_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function recette(): BelongsTo
    {
        return $this->belongsTo(Recette::class);
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
