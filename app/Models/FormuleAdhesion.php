<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class FormuleAdhesion extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'formules_adhesion';

    protected $fillable = [
        'association_id',
        'nom',
        'description',
        'mode',
        'duree_mois',
        'montant_par_defaut',
        'deductible_fiscal',
        'sous_categorie_id',
        'actif',
    ];

    protected $casts = [
        'association_id' => 'integer',
        'duree_mois' => 'integer',
        'montant_par_defaut' => 'decimal:2',
        'deductible_fiscal' => 'boolean',
        'sous_categorie_id' => 'integer',
        'actif' => 'boolean',
    ];

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class, 'sous_categorie_id');
    }

    public function helloAssoTierMappings(): MorphMany
    {
        return $this->morphMany(HelloAssoTierMapping::class, 'target');
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function isModeExercice(): bool
    {
        return $this->mode === 'exercice';
    }

    public function isModeDuree(): bool
    {
        return $this->mode === 'duree';
    }
}
