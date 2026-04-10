<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Provision;
use Illuminate\Support\Collection;

final class ProvisionService
{
    /**
     * Provisions de l'exercice N, avec montant signé et infos sous-catégorie.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, sous_categorie_id: int, sous_categorie_nom: string, categorie_nom: string}>
     */
    public function provisionsExercice(int $annee): Collection
    {
        return Provision::forExercice($annee)
            ->with('sousCategorie.categorie')
            ->orderBy('type')
            ->orderBy('libelle')
            ->get()
            ->map(fn (Provision $p) => [
                'id' => $p->id,
                'libelle' => $p->libelle,
                'type' => $p->type->value,
                'montant' => (float) $p->montant,
                'montant_signe' => $p->montantSigne(),
                'sous_categorie_id' => $p->sous_categorie_id,
                'sous_categorie_nom' => $p->sousCategorie->nom,
                'categorie_nom' => $p->sousCategorie->categorie->nom,
            ]);
    }

    /**
     * Extournes = provisions de N−1, montant signé inversé.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, sous_categorie_id: int, sous_categorie_nom: string, categorie_nom: string}>
     */
    public function extournesExercice(int $annee): Collection
    {
        return Provision::forExercice($annee - 1)
            ->with('sousCategorie.categorie')
            ->orderBy('type')
            ->orderBy('libelle')
            ->get()
            ->map(fn (Provision $p) => [
                'id' => $p->id,
                'libelle' => $p->libelle,
                'type' => $p->type->value,
                'montant' => (float) $p->montant,
                'montant_signe' => -$p->montantSigne(),
                'sous_categorie_id' => $p->sous_categorie_id,
                'sous_categorie_nom' => $p->sousCategorie->nom,
                'categorie_nom' => $p->sousCategorie->categorie->nom,
            ]);
    }

    /**
     * Somme nette des provisions de l'exercice (impact résultat N).
     */
    public function totalProvisions(int $annee): float
    {
        return round(
            (float) Provision::forExercice($annee)
                ->get()
                ->sum(fn (Provision $p) => $p->montantSigne()),
            2,
        );
    }

    /**
     * Somme nette des extournes (impact résultat N, provisions N−1 inversées).
     */
    public function totalExtournes(int $annee): float
    {
        return round(
            (float) Provision::forExercice($annee - 1)
                ->get()
                ->sum(fn (Provision $p) => -$p->montantSigne()),
            2,
        );
    }
}
