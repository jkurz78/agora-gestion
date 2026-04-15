<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeCategorie;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Categorie extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'nom',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeCategorie::class,
        ];
    }

    public function sousCategories(): HasMany
    {
        return $this->hasMany(SousCategorie::class, 'categorie_id');
    }
}
