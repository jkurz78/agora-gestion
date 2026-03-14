<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tiers extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'pour_depenses',
        'pour_recettes',
        'date_adhesion',
        'statut_membre',
        'notes_membre',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
            'date_adhesion' => 'date',
            'statut_membre' => 'string',
            'notes_membre' => 'string',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class);
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }

    public function recettes(): HasMany
    {
        return $this->hasMany(Recette::class);
    }

    /**
     * @param  Builder<Tiers>  $query
     */
    public function scopeMembres(Builder $query): Builder
    {
        return $query->whereNotNull('statut_membre');
    }
}
