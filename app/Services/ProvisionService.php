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
     * Rattachements de l'exercice N, avec impact signé sur le résultat et infos compte/famille.
     *
     * Le compte est lu via l'origine (la ligne d'écriture pour un CCA/PCA, le compte
     * saisi pour un FNP/PAR) — jamais directement sur `compte_id`, qui est nul pour
     * un rattachement adossé. `type` porte le sigle du cas (cca, pca, fnp, par).
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
     */
    public function provisionsExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee)
                ->with('ligne.compte', 'compte')
                ->orderBy('id')
                ->get(),
            fn (Provision $p) => $this->impactResultat($p),
        );
    }

    /**
     * Extournes = rattachements de N−1, impact signé inversé.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
     */
    public function extournesExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee - 1)
                ->with('ligne.compte', 'compte')
                ->orderBy('id')
                ->get(),
            fn (Provision $p) => -$this->impactResultat($p),
        );
    }

    /**
     * Transforme une collection de Provision en payload (compte/famille), en préchargeant
     * les familles en un seul aller (N+1 fix — même pattern que Famille::pourComptes).
     *
     * @param  Collection<int, Provision>  $provisions
     * @param  callable(Provision): float  $montantSigne
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
     */
    private function mapper(Collection $provisions, callable $montantSigne): Collection
    {
        /** @var EloquentCollection<int, Compte> $comptes */
        $comptes = EloquentCollection::make($provisions->map(fn (Provision $p) => $p->compteResultat())->filter());
        $familles = Famille::pourComptes($comptes);

        return $provisions->map(function (Provision $p) use ($montantSigne, $familles) {
            $compte = $p->compteResultat();
            $famille = $compte !== null ? $familles->get(substr($compte->numero_pcg, 0, 2)) : null;

            return [
                'id' => $p->id,
                'libelle' => $p->libelle ?? $p->ligne?->libelle ?? '',
                'type' => $compte !== null ? $p->cas()->value : '',
                'montant' => (float) $p->montant,
                'montant_signe' => $montantSigne($p),
                'compte_id' => (int) $compte?->id,
                'compte_nom' => $compte?->intitule ?? 'Compte supprimé',
                'famille_nom' => $famille?->libelle() ?? $compte?->numero_pcg ?? 'Famille inconnue',
            ];
        });
    }

    /**
     * Impact du rattachement sur le résultat de l'exercice N.
     *
     * La dotation débite le compte de résultat pour un PCA (produit retiré) et une
     * FNP (charge ajoutée) : le résultat baisse. Elle le crédite pour un CCA
     * (charge retirée) et un PAR (produit ajouté) : le résultat monte.
     */
    private function impactResultat(Provision $p): float
    {
        if ($p->compteResultat() === null) {
            return 0.0;
        }

        $montant = (float) $p->montant;

        return $p->cas()->dotationDebiteLeResultat() ? -$montant : $montant;
    }

    /**
     * Somme nette des rattachements de l'exercice (impact résultat N).
     */
    public function totalProvisions(int $annee): float
    {
        return round(
            (float) Provision::forExercice($annee)
                ->with('ligne.compte', 'compte')
                ->get()
                ->sum(fn (Provision $p) => $this->impactResultat($p)),
            2,
        );
    }

    /**
     * Somme nette des extournes (impact résultat N, rattachements N−1 inversés).
     */
    public function totalExtournes(int $annee): float
    {
        return round(
            (float) Provision::forExercice($annee - 1)
                ->with('ligne.compte', 'compte')
                ->get()
                ->sum(fn (Provision $p) => -$this->impactResultat($p)),
            2,
        );
    }
}
