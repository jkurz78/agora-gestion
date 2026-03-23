<?php

declare(strict_types=1);

namespace App\Models;

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
        'entreprise',
        'email',
        'telephone',
        'adresse_ligne1',
        'code_postal',
        'ville',
        'pays',
        'date_naissance',
        'pour_depenses',
        'pour_recettes',
        'est_helloasso',
        'helloasso_nom',
        'helloasso_prenom',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
            'est_helloasso' => 'boolean',
            'date_naissance' => 'date',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->entreprise ?? $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
