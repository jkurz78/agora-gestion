<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SousCategorie extends Model
{
    use HasFactory;

    protected $table = 'sous_categories';

    protected $fillable = [
        'categorie_id',
        'nom',
        'code_cerfa',
    ];

    protected function casts(): array
    {
        return [
            'categorie_id' => 'integer',
        ];
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'sous_categorie_id');
    }

    public function depenseLignes(): HasMany
    {
        return $this->hasMany(DepenseLigne::class, 'sous_categorie_id');
    }

    public function recetteLignes(): HasMany
    {
        return $this->hasMany(RecetteLigne::class, 'sous_categorie_id');
    }
}
