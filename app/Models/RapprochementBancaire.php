<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutRapprochement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RapprochementBancaire extends Model
{
    use HasFactory;

    protected $table = 'rapprochements_bancaires';

    protected $fillable = [
        'association_id',
        'compte_id',
        'date_fin',
        'solde_ouverture',
        'solde_fin',
        'statut',
        'saisi_par',
        'verrouille_at',
    ];

    protected function casts(): array
    {
        return [
            'date_fin' => 'date',
            'solde_ouverture' => 'decimal:2',
            'solde_fin' => 'decimal:2',
            'statut' => StatutRapprochement::class,
            'verrouille_at' => 'datetime',
            'compte_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'rapprochement_id');
    }

    public function depenses(): HasMany
    {
        return $this->transactions()->where('type', 'depense');
    }

    public function recettes(): HasMany
    {
        return $this->transactions()->where('type', 'recette');
    }

    public function virementsSource(): HasMany
    {
        return $this->hasMany(VirementInterne::class, 'rapprochement_source_id');
    }

    public function virementsDestination(): HasMany
    {
        return $this->hasMany(VirementInterne::class, 'rapprochement_destination_id');
    }

    public function isVerrouille(): bool
    {
        return $this->statut === StatutRapprochement::Verrouille
            && $this->verrouille_at !== null;
    }

    public function isEnCours(): bool
    {
        return $this->statut === StatutRapprochement::EnCours;
    }
}
