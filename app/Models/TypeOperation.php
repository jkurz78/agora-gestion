<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TypeOperation extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'nom',
        'libelle_article',
        'description',
        'sous_categorie_id',
        'nombre_seances',
        'reserve_adherents',
        'actif',
        'logo_path',
        'email_from',
        'email_from_name',
        'attestation_medicale_path',
        'formulaire_actif',
        'formulaire_prescripteur',
        'formulaire_parcours_therapeutique',
        'formulaire_droit_image',
        'formulaire_prescripteur_titre',
        'formulaire_qualificatif_atelier',
    ];

    protected function casts(): array
    {
        return [
            'reserve_adherents' => 'boolean',
            'actif' => 'boolean',
            'nombre_seances' => 'integer',
            'sous_categorie_id' => 'integer',
            'formulaire_actif' => 'boolean',
            'formulaire_prescripteur' => 'boolean',
            'formulaire_parcours_therapeutique' => 'boolean',
            'formulaire_droit_image' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TypeOperation>  $query
     */
    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(TypeOperationTarif::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function seanceDefaults(): HasMany
    {
        return $this->hasMany(TypeOperationSeance::class)->orderBy('numero');
    }

    /**
     * Adresse d'expédition effective : celle du type d'opération si définie,
     * sinon repli sur l'adresse de l'association (Paramètres > Communication).
     */
    public function effectiveEmailFrom(): ?string
    {
        return $this->email_from ?: Association::find(1)?->email_from;
    }

    /**
     * Nom d'expédition effectif, cohérent avec effectiveEmailFrom().
     */
    public function effectiveEmailFromName(): ?string
    {
        return $this->email_from
            ? ($this->email_from_name ?: null)
            : Association::find(1)?->email_from_name;
    }
}
