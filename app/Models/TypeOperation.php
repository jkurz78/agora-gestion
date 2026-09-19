<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CurrentAssociation;
use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TypeOperation extends TenantModel
{
    use HasFactory;
    use TenantStorage;

    public const LIBELLE_PARTICIPATION_SEANCE_DEFAUT = 'Kiné';

    protected $fillable = [
        'association_id',
        'nom',
        'libelle_article',
        'description',
        'compte_id',
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
        'participation_seance_active',
        'participation_seance_libelle',
    ];

    protected function casts(): array
    {
        return [
            'reserve_adherents' => 'boolean',
            'actif' => 'boolean',
            'nombre_seances' => 'integer',
            'compte_id' => 'integer',
            'formulaire_actif' => 'boolean',
            'formulaire_prescripteur' => 'boolean',
            'formulaire_parcours_therapeutique' => 'boolean',
            'formulaire_droit_image' => 'boolean',
            'participation_seance_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TypeOperation>  $query
     */
    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * Libellé de la participation optionnelle collectée à chaque séance
     * (colonne oui / non), ou null si l'option est inactive. SEULE condition
     * d'affichage de cette colonne : grille Séances, feuille d'émargement,
     * matrice PDF et export Excel passent tous par ici.
     */
    public function libelleParticipationSeance(): ?string
    {
        if (! $this->participation_seance_active) {
            return null;
        }

        $libelle = trim((string) $this->participation_seance_libelle);

        return $libelle !== '' ? $libelle : self::LIBELLE_PARTICIPATION_SEANCE_DEFAUT;
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
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
     * Full local-disk path for the type-operation logo, or null if not set.
     */
    public function typeOpLogoFullPath(): ?string
    {
        return $this->logo_path
            ? $this->storagePath('type-operations/'.$this->id.'/'.basename($this->logo_path))
            : null;
    }

    /**
     * Full local-disk path for the attestation médicale, or null if not set.
     */
    public function typeOpAttestationFullPath(): ?string
    {
        return $this->attestation_medicale_path
            ? $this->storagePath('type-operations/'.$this->id.'/'.basename($this->attestation_medicale_path))
            : null;
    }

    /**
     * Adresse d'expédition effective : celle du type d'opération si définie,
     * sinon repli sur l'adresse de l'association (Paramètres > Communication).
     */
    public function effectiveEmailFrom(): ?string
    {
        return $this->email_from ?: CurrentAssociation::tryGet()?->email_from;
    }

    /**
     * Nom d'expédition effectif, cohérent avec effectiveEmailFrom().
     */
    public function effectiveEmailFromName(): ?string
    {
        return $this->email_from
            ? ($this->email_from_name ?: null)
            : CurrentAssociation::tryGet()?->email_from_name;
    }
}
