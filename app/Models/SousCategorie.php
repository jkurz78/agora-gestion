<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SousCategorie extends TenantModel
{
    use HasFactory;

    protected $table = 'sous_categories';

    protected $fillable = [
        'association_id',
        'categorie_id',
        'nom',
        'code_cerfa',
        'pour_dons',
        'pour_cotisations',
        'pour_inscriptions',
        'pour_frais_kilometriques',
    ];

    protected function casts(): array
    {
        return [
            'categorie_id' => 'integer',
            'pour_dons' => 'boolean',
            'pour_cotisations' => 'boolean',
            'pour_inscriptions' => 'boolean',
            'pour_frais_kilometriques' => 'boolean',
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
