<?php

// app/Models/Tiers.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }
}
