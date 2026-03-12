<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutOperation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Operation extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'description',
        'date_debut',
        'date_fin',
        'nombre_seances',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutOperation::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
        ];
    }

    public function depenseLignes(): HasMany
    {
        return $this->hasMany(DepenseLigne::class);
    }

    public function recetteLignes(): HasMany
    {
        return $this->hasMany(RecetteLigne::class);
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }
}
