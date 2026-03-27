<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TypeOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'nom',
        'description',
        'sous_categorie_id',
        'nombre_seances',
        'confidentiel',
        'reserve_adherents',
        'actif',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'confidentiel' => 'boolean',
            'reserve_adherents' => 'boolean',
            'actif' => 'boolean',
            'nombre_seances' => 'integer',
            'sous_categorie_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<TypeOperation>  $query
     */
    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(TypeOperationTarif::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }
}
