<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CompteBancaire extends TenantModel
{
    use HasFactory;

    protected $table = 'comptes_bancaires';

    protected $fillable = [
        'association_id',
        'nom',
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
        'actif_recettes_depenses',
        'est_systeme',
    ];

    protected function casts(): array
    {
        return [
            'solde_initial' => 'decimal:2',
            'date_solde_initial' => 'date',
            'actif_recettes_depenses' => 'boolean',
            'est_systeme' => 'boolean',
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
}
