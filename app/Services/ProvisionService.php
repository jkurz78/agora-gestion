<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Compte;
use App\Models\Famille;
use App\Models\Provision;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class ProvisionService
{
    /**
     * Rattachements de l'exercice N, avec impact signé sur le résultat et infos compte/famille.
     *
     * Le compte est lu via l'origine (la ligne d'écriture pour un CCA/PCA, le compte
     * saisi pour un FNP/PAR) — jamais directement sur `compte_id`, qui est nul pour
     * un rattachement adossé. `cas` porte la valeur de CasProvision (cca, pca, fnp,
     * par), ou une chaîne vide quand le compte est introuvable.
     *
     * @return Collection<int, array{id: int, libelle: string, cas: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
     */
    public function provisionsExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee)
                ->avecCompteResultat()
                ->orderBy('id')
                ->get(),
            fn (Provision $p) => $this->impactResultat($p),
        );
    }

    /**
     * Extournes = rattachements de N−1, impact signé inversé.
     *
     * @return Collection<int, array{id: int, libelle: string, cas: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
     */
    public function extournesExercice(int $annee): Collection
    {
        return $this->mapper(
            Provision::forExercice($annee - 1)
                ->avecCompteResultat()
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
     * @return Collection<int, array{id: int, libelle: string, cas: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
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
                'cas' => $compte !== null ? $p->cas()->value : '',
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
            // Le total du récapitulatif est alors sous-évalué : on le dit au journal
            // plutôt que de le taire, sans lever — une ligne mauvaise ne doit pas
            // casser tout le récapitulatif.
            Log::warning('Rattachement sans compte de résultat : impact compté à zéro', [
                'provision_id' => (int) $p->id,
                'transaction_ligne_id' => $p->transaction_ligne_id !== null ? (int) $p->transaction_ligne_id : null,
                'compte_id' => $p->compte_id !== null ? (int) $p->compte_id : null,
            ]);

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
                ->avecCompteResultat()
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
                ->avecCompteResultat()
                ->get()
                ->sum(fn (Provision $p) => -$this->impactResultat($p)),
            2,
        );
    }
}
