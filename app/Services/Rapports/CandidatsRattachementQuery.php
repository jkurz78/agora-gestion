<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Tiers;
use App\Services\Compta\RattachementExerciceService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Détection des candidats au rattachement à l'exercice suivant (CCA/PCA), zone « À rattacher » de l'écran
 * (spec § 6.1, D6).
 *
 * Est CANDIDATE toute ligne de charge (classe 6, au débit) ou de produit (classe 7, au crédit) d'une pièce de
 * l'exercice, ventilée sur une opération dont le `date_debut` tombe APRÈS la fin de l'exercice : l'argent a été
 * dépensé ou encaissé dans N pour une activité qui n'existe que dans N+1. UNE seule requête, qui lit la
 * ventilation sur `transaction_lignes.operation_id`.
 *
 * ── Ce que la requête reproduit ─────────────────────────────────────────────
 *
 * Elle REPRODUIT en SQL les gardes d'éligibilité de RattachementExerciceService::motifDeRefus(), qui reste
 * l'autorité à l'écriture (rattacher() rejuge la ligne sous verrou) : ligne d'une pièce vivante, classe 6 ou 7,
 * sens (D9), journal des opérations diverses (D14), annulation ou pièce annulée au plus tard en N (D13), écriture
 * de rattachement, ligne déjà rattachée. Deux lectures de la même règle peuvent diverger ; un test
 * (CandidatsRattachementQueryTest, « accord ») vérifie donc, sur un jeu de lignes de toutes les formes, qu'elle
 * ne propose rien que le service refuse, ET qu'elle ne laisse de côté rien que le service accepte dont
 * l'opération démarre après l'exercice. Toute garde ajoutée à motifDeRefus() doit l'être ici.
 *
 * Sont lus comme le service les lit : les suppressions logiques (pièce, ligne, compte, opération, annulation),
 * l'isolation par association des annulations (Extourne est sous scope tenant) et la colonne `journal`, qui peut
 * être nulle : un journal absent n'est pas un journal OD (`IS NULL OR <> 'od'`, un `<> 'od'` seul l'exclurait).
 * Les rattachements, eux, se lisent sans filtre : comme estRattachee(), qui compte ce que compte la clé étrangère.
 *
 * Ce que la requête ne contrôle PAS : la clôture de l'exercice N ou N+1, qui se contrôle par période à l'écran
 * et sous verrou dans rattacher().
 *
 * ── Les lignes ventilées par affectations (D4) ──────────────────────────────
 *
 * Elles ne sont pas exclues mais ÉCARTÉES : rendues à part, avec leur motif (celui du service), jamais omises en
 * silence. LIMITE : la requête joint l'opération sur `transaction_lignes.operation_id`, comme le dit la spec ; une
 * ligne dont la ventilation ne vit QUE dans ses affectations (`operation_id` nul, cas de la subvention du § 2.4)
 * n'est pas atteinte et n'est donc pas écartée ici — la recherche libre, qui juge par motifDeRefus(), la refuse.
 *
 * ── Les bornes : des intervalles semi-ouverts ───────────────────────────────
 *
 * Les dates sont comparées `>= début` et `< début de l'exercice suivant`, jamais `BETWEEN début AND fin`. Sous
 * SQLite une colonne `date` écrite par Eloquent est le texte « 2026-08-31 00:00:00 », qui est plus grand que
 * « 2026-08-31 » : un `<= fin` ou un BETWEEN exclurait le dernier jour de l'exercice (et un `> fin` y verrait un
 * jour postérieur). Avec des bornes « Y-m-d » et des comparaisons strictes sur la borne haute, la même requête
 * est juste que la colonne soit stockée « Y-m-d » ou « Y-m-d H:i:s », sous SQLite comme sous MariaDB.
 *
 * ── Isolation par association ───────────────────────────────────────────────
 *
 * `transaction_lignes` n'a pas de colonne d'association : l'isolation tient à un filtre PAR TABLE jointe
 * (pièce, compte, opération, et tiers et annulations dans leurs sous-requêtes), tous sur
 * TenantContext::currentId(). Hors contexte, la requête ne rend rien (fermé par défaut). Un test par filtre.
 *
 * ── Ce que rend chaque ligne ────────────────────────────────────────────────
 *
 * transaction_ligne_id, transaction_id, piece_date (CarbonImmutable), numero_piece, piece_libelle, ligne_libelle,
 * tiers_id et tiers_nom (le tiers de la PIÈCE, D3 ; nuls s'il n'y en a pas ou s'il est d'une autre association),
 * numero_pcg, compte_intitule, classe, operation_id, operation_nom, operation_debut et operation_fin
 * (CarbonImmutable ; la seconde peut être nulle), montant (le plus grand du débit et du crédit, celui que le
 * rattachement reprend, D12) et nb_affectations. Les écartées portent en plus `motif`.
 *
 * Le nom du tiers est le libellé de liste de Tiers::sqlLibelleListe() (« Prénom NOM », ou « Raison sociale
 * (Prénom NOM) ») : une jointure gauche simple, scopée, sans GROUP BY.
 */
final class CandidatsRattachementQuery
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Les candidats de l'exercice $exercice (l'année de son premier jour, comme partout), triés par date de
     * pièce, puis par pièce et par ligne.
     *
     * `eligibles` : les lignes que l'écran propose de rattacher. `ecartees` : celles qui le seraient sans leurs
     * affectations, chacune avec son `motif`.
     *
     * @return array{eligibles: Collection<int, object>, ecartees: Collection<int, object>}
     */
    public function pourExercice(int $exercice): array
    {
        // Fermé par défaut : sans contexte, l'identifiant 0 n'est celui d'aucune association.
        $associationId = (int) TenantContext::currentId();

        $debut = $this->exerciceService->dateRange($exercice)['start']->toDateString();
        $debutSuivant = $this->exerciceService->dateRange($exercice + 1)['start']->toDateString();

        $lignes = DB::table('transaction_lignes as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->join('comptes as c', 'c.id', '=', 'tl.compte_id')
            ->join('operations as o', 'o.id', '=', 'tl.operation_id')
            // Jointure d'affichage : un tiers d'une autre association n'est ni nommé ni rendu.
            ->leftJoin('tiers as ti', function ($jointure) use ($associationId): void {
                $jointure->on('ti.id', '=', 't.tiers_id')->where('ti.association_id', '=', $associationId);
            })
            ->select([
                'tl.id as transaction_ligne_id',
                't.id as transaction_id',
                't.date as piece_date',
                't.numero_piece',
                't.libelle as piece_libelle',
                'tl.libelle as ligne_libelle',
                'ti.id as tiers_id',
                'c.numero_pcg',
                'c.intitule as compte_intitule',
                'c.classe',
                'o.id as operation_id',
                'o.nom as operation_nom',
                'o.date_debut as operation_debut',
                'o.date_fin as operation_fin',
            ])
            ->selectRaw(Tiers::sqlLibelleListe('ti').' as tiers_nom')
            // GREATEST() n'existe pas sous SQLite.
            ->selectRaw('CASE WHEN tl.debit > tl.credit THEN tl.debit ELSE tl.credit END as montant')
            ->selectSub(
                DB::table('transaction_ligne_affectations as a')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('a.transaction_ligne_id', 'tl.id'),
                'nb_affectations',
            )
            // L'isolation : un filtre par table jointe.
            ->where('t.association_id', $associationId)
            ->where('c.association_id', $associationId)
            ->where('o.association_id', $associationId)
            // Ce que les modèles ne voient pas.
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('o.deleted_at')
            // La pièce est de l'exercice, l'opération démarre après lui : intervalles semi-ouverts.
            ->where('t.date', '>=', $debut)
            ->where('t.date', '<', $debutSuivant)
            ->where('o.date_debut', '>=', $debutSuivant)
            // Une écriture de rattachement n'est jamais candidate.
            ->whereNull('t.provision_id')
            // D9 : une classe 7 se rattache au crédit, une classe 6 au débit. Ce bloc porte aussi la garde de
            // classe : une ligne de classe 4 ou 5 ne satisfait aucune des deux branches.
            ->where(function (Builder $sens): void {
                $sens->where(function (Builder $produit): void {
                    $produit->where('c.classe', 7)->where('tl.credit', '>', 0);
                })->orWhere(function (Builder $charge): void {
                    $charge->where('c.classe', 6)->where('tl.debit', '>', 0);
                });
            })
            // D14 : une écriture d'inventaire ne se rattache pas. Un journal absent n'est pas un journal OD.
            ->where(function (Builder $journal): void {
                $journal->whereNull('t.journal')->orWhere('t.journal', '<>', 'od');
            })
            // D13 : une pièce qui EST une annulation ne se rattache jamais.
            ->where('t.type_ecriture', '<>', 'extourne')
            ->whereNotExists(function (Builder $annulation) use ($associationId): void {
                $annulation->selectRaw('1')
                    ->from('extournes as e')
                    ->whereColumn('e.transaction_extourne_id', 't.id')
                    ->whereNull('e.deleted_at')
                    ->where('e.association_id', $associationId);
            })
            // D13 : une pièce annulée ne se rattache que si l'annulation est datée APRÈS N. La date qui compte est
            // celle de la pièce miroir ; miroir introuvable ou supprimé : exclue, par prudence.
            ->where(function (Builder $annulee) use ($associationId, $debutSuivant): void {
                $annulee->whereNull('t.extournee_at')
                    ->orWhereExists(function (Builder $tardive) use ($associationId, $debutSuivant): void {
                        $tardive->selectRaw('1')
                            ->from('extournes as ea')
                            ->join('transactions as m', 'm.id', '=', 'ea.transaction_extourne_id')
                            ->whereColumn('ea.transaction_origine_id', 't.id')
                            ->whereNull('ea.deleted_at')
                            ->where('ea.association_id', $associationId)
                            ->whereNull('m.deleted_at')
                            ->where('m.association_id', $associationId)
                            ->where('m.date', '>=', $debutSuivant);
                    });
            })
            // Déjà rattachée : comme estRattachee(), sans aucun filtre (ni association, ni suppression logique).
            ->whereNotExists(function (Builder $rattachement): void {
                $rattachement->selectRaw('1')
                    ->from('provisions as p')
                    ->whereColumn('p.transaction_ligne_id', 'tl.id');
            })
            ->orderBy('t.date')
            ->orderBy('t.id')
            ->orderBy('tl.id')
            ->get()
            ->map(fn (object $ligne): object => $this->hydrater($ligne));

        // D4 : les lignes ventilées par affectations sont écartées avec leur motif, jamais omises.
        [$eligibles, $ecartees] = $lignes->partition(fn (object $ligne): bool => $ligne->nb_affectations === 0);

        return [
            'eligibles' => $eligibles->values(),
            'ecartees' => $ecartees
                ->map(function (object $ligne): object {
                    $ligne->motif = RattachementExerciceService::MOTIF_AFFECTATIONS;

                    return $ligne;
                })
                ->values(),
        ];
    }

    /**
     * Des types stables d'un moteur à l'autre : MariaDB rend les décimaux en chaînes, SQLite les dates avec une
     * heure.
     */
    private function hydrater(object $ligne): object
    {
        $nomDuTiers = trim((string) $ligne->tiers_nom);

        return (object) [
            'transaction_ligne_id' => (int) $ligne->transaction_ligne_id,
            'transaction_id' => (int) $ligne->transaction_id,
            'piece_date' => CarbonImmutable::parse((string) $ligne->piece_date)->startOfDay(),
            'numero_piece' => $ligne->numero_piece,
            'piece_libelle' => $ligne->piece_libelle,
            'ligne_libelle' => $ligne->ligne_libelle,
            'tiers_id' => $ligne->tiers_id !== null ? (int) $ligne->tiers_id : null,
            'tiers_nom' => $ligne->tiers_id !== null && $nomDuTiers !== '' ? $nomDuTiers : null,
            'numero_pcg' => $ligne->numero_pcg,
            'compte_intitule' => $ligne->compte_intitule,
            'classe' => (int) $ligne->classe,
            'operation_id' => (int) $ligne->operation_id,
            'operation_nom' => $ligne->operation_nom,
            'operation_debut' => CarbonImmutable::parse((string) $ligne->operation_debut)->startOfDay(),
            'operation_fin' => $ligne->operation_fin !== null ? CarbonImmutable::parse((string) $ligne->operation_fin)->startOfDay() : null,
            'montant' => round((float) $ligne->montant, 2),
            'nb_affectations' => (int) $ligne->nb_affectations,
        ];
    }
}
