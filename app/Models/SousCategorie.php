<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use Illuminate\Database\Eloquent\Builder;
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

    public function transactionLignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class, 'sous_categorie_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UsageSousCategorie::class);
    }

    public function hasUsage(UsageComptable $usage): bool
    {
        return $this->usages()->where('usage', $usage->value)->exists();
    }

    public function scopeForUsage(Builder $query, UsageComptable $usage): Builder
    {
        return $query->whereHas('usages', fn (Builder $q) => $q->where('usage', $usage->value));
    }
}
