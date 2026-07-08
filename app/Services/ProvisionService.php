<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Compte;
use App\Models\Famille;
use App\Models\Provision;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class ProvisionService
{
    /**
     * Provisions de l'exercice N, avec montant signé et infos compte/famille.
     *
     * DC-5 — lit compte_id/compte (dissolution sous_categories → comptes) au lieu de
     * sous_categorie/categorie. Clés de payload conservées (sous_categorie_id,
     * sous_categorie_nom, categorie_nom) pour ne pas casser les consommateurs Livewire —
     * bascule des clés en DC-6/8.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, sous_categorie_id: int, sous_categorie_nom: string, categorie_nom: string}>
     */
    public function provisionsExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee)
                ->with('compte')
                ->orderBy('type')
                ->orderBy('libelle')
                ->get(),
            fn (Provision $p) => $p->montantSigne(),
        );
    }

    /**
     * Extournes = provisions de N−1, montant signé inversé.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, sous_categorie_id: int, sous_categorie_nom: string, categorie_nom: string}>
     */
    public function extournesExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee - 1)
                ->with('compte')
                ->orderBy('type')
                ->orderBy('libelle')
                ->get(),
            fn (Provision $p) => -$p->montantSigne(),
        );
    }

    /**
     * Transforme une collection de Provision en payload (compte/famille), en préchargeant
     * les familles en un seul aller (N+1 fix — même pattern que Famille::pourComptes).
     *
     * @param  Collection<int, Provision>  $provisions
     * @param  callable(Provision): float  $montantSigne
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, sous_categorie_id: int, sous_categorie_nom: string, categorie_nom: string}>
     */
    private function mapper(Collection $provisions, callable $montantSigne): Collection
    {
        /** @var EloquentCollection<int, Compte> $comptes */
        $comptes = EloquentCollection::make($provisions->map(fn (Provision $p) => $p->compte)->filter());
        $familles = Famille::pourComptes($comptes);

        return $provisions->map(function (Provision $p) use ($montantSigne, $familles) {
            $compte = $p->compte;
            $famille = $compte !== null ? $familles->get(substr($compte->numero_pcg, 0, 2)) : null;

            return [
                'id' => $p->id,
                'libelle' => $p->libelle,
                'type' => $p->type->value,
                'montant' => (float) $p->montant,
                'montant_signe' => $montantSigne($p),
                'sous_categorie_id' => $p->compte_id ?? $p->sous_categorie_id,
                'sous_categorie_nom' => $compte->intitule ?? $p->sousCategorie->nom,
                'categorie_nom' => $famille?->libelle() ?? $compte?->numero_pcg ?? $p->sousCategorie->categorie->nom,
            ];
        });
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
