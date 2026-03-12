<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutMembre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Membre extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'date_adhesion',
        'statut',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutMembre::class,
            'date_adhesion' => 'date',
        ];
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class);
    }
}
