<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutDevis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Devis extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tiers_id',
        'date_emission',
        'date_validite',
        'libelle',
        'statut',
        'montant_total',
        'saisi_par_user_id',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDevis::class,
            'montant_total' => 'decimal:2',
            'exercice' => 'integer',
            'tiers_id' => 'integer',
            'association_id' => 'integer',
            'saisi_par_user_id' => 'integer',
            'accepte_par_user_id' => 'integer',
            'refuse_par_user_id' => 'integer',
            'annule_par_user_id' => 'integer',
            'date_emission' => 'date',
            'date_validite' => 'date',
            'accepte_le' => 'datetime',
            'refuse_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DevisLigne::class)->orderBy('ordre');
    }

    public function accepteParUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepte_par_user_id');
    }

    public function refuseParUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refuse_par_user_id');
    }

    public function annuleParUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par_user_id');
    }

    public function saisiParUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par_user_id');
    }

    public function facture(): HasOne
    {
        return $this->hasOne(Facture::class, 'devis_id');
    }

    public function aDejaUneFacture(): bool
    {
        return $this->facture()->exists();
    }
}
