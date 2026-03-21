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
        'helloasso_id',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses'  => 'boolean',
            'pour_recettes'  => 'boolean',
            'date_naissance' => 'date',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->entreprise ?? $this->nom;
        }

        return trim(($this->prenom ? $this->prenom . ' ' : '') . $this->nom);
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
