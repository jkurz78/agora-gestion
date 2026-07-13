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
     * Lit compte_id/compte et la famille dérivée. Le payload compte-first expose
     * compte_id, compte_nom et famille_nom aux consommateurs de rapports.
     *
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
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
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
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
     * @return Collection<int, array{id: int, libelle: string, type: string, montant: float, montant_signe: float, compte_id: int, compte_nom: string, famille_nom: string}>
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
                'compte_id' => (int) $p->compte_id,
                'compte_nom' => $compte?->intitule ?? 'Compte supprimé',
                'famille_nom' => $famille?->libelle() ?? $compte?->numero_pcg ?? 'Famille inconnue',
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
