<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class FormuleAdhesion extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'formules_adhesion';

    protected $fillable = [
        'association_id',
        'nom',
        'description',
        'mode',
        'duree_mois',
        'duree_jours',
        'montant_par_defaut',
        'deductible_fiscal',
        'sous_categorie_id',
        'actif',
        'est_helloasso',
        'helloasso_form_slug',
        'helloasso_tier_id',
        'helloasso_start_date',
        'helloasso_end_date',
    ];

    protected $casts = [
        'association_id' => 'integer',
        'duree_mois' => 'integer',
        'duree_jours' => 'integer',
        'montant_par_defaut' => 'decimal:2',
        'deductible_fiscal' => 'boolean',
        'sous_categorie_id' => 'integer',
        'actif' => 'boolean',
        'est_helloasso' => 'boolean',
        'helloasso_tier_id' => 'integer',
        'helloasso_start_date' => 'date',
        'helloasso_end_date' => 'date',
    ];

    protected static function booted(): void
    {
        parent::booted();

        self::saving(function (FormuleAdhesion $formule): void {
            // Contrainte XOR : mode=duree doit avoir exactement une unité (mois OU jours).
            // Exception : formules HelloAsso en mode Custom — elles utilisent helloasso_start_date
            // et helloasso_end_date à la place, pas duree_mois ni duree_jours.
            if ($formule->mode === 'duree' && ! $formule->est_helloasso) {
                $hasMois = $formule->duree_mois !== null;
                $hasJours = $formule->duree_jours !== null;
                if ($hasMois === $hasJours) {
                    throw new \DomainException(
                        'Une formule en mode "durée" doit avoir exactement une unité : mois OU jours.'
                    );
                }
            }

            if (! $formule->actif) {
                return;
            }

            // La contrainte "1 active par sous-cat" ne s'applique qu'aux formules MANUELLES.
            // Les formules HelloAsso peuvent être plusieurs sur la même sous-cat (4 paliers
            // d'un form Membership = 4 formules) — la priorité 1 du resolver utilise
            // (helloasso_form_slug, helloasso_tier_id), pas la sous-cat.
            if ($formule->est_helloasso) {
                return;
            }

            $existante = static::query()
                ->where('sous_categorie_id', $formule->sous_categorie_id)
                ->where('actif', true)
                ->where('est_helloasso', false)
                ->when($formule->exists, fn ($q) => $q->where('id', '!=', $formule->id))
                ->exists();

            if ($existante) {
                throw new \DomainException(
                    "La sous-catégorie a déjà une formule active. Désactivez-la avant d'en activer une nouvelle."
                );
            }
        });
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class, 'sous_categorie_id');
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function isModeExercice(): bool
    {
        return $this->mode === 'exercice';
    }

    public function isModeDuree(): bool
    {
        return $this->mode === 'duree';
    }

    public function isModeIllimite(): bool
    {
        return $this->mode === 'illimite';
    }

    /** Retourne true si la formule est en mode durée avec une unité en jours. */
    public function isUniteJours(): bool
    {
        return $this->mode === 'duree' && $this->duree_jours !== null;
    }
}
