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
        'pour_dons',
        'pour_cotisations',
    ];

    protected function casts(): array
    {
        return [
            'categorie_id'     => 'integer',
            'pour_dons'        => 'boolean',
            'pour_cotisations' => 'boolean',
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

    public function transactionLignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class, 'sous_categorie_id');
    }
}
