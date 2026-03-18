<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CompteBancaire extends Model
{
    use HasFactory;

    protected $table = 'comptes_bancaires';

    protected $fillable = [
        'nom',
        'iban',
        'solde_initial',
        'date_solde_initial',
        'actif_recettes_depenses',
        'actif_dons_cotisations',
    ];

    protected function casts(): array
    {
        return [
            'solde_initial' => 'decimal:2',
            'date_solde_initial' => 'date',
            'actif_recettes_depenses' => 'boolean',
            'actif_dons_cotisations' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'compte_id');
    }

    public function depenses(): HasMany
    {
        return $this->transactions()->where('type', 'depense');
    }

    public function recettes(): HasMany
    {
        return $this->transactions()->where('type', 'recette');
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class, 'compte_id');
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class, 'compte_id');
    }
}
