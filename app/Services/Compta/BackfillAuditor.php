<?php

declare(strict_types=1);

namespace App\Services\Compta;

use Illuminate\Support\Facades\DB;

/**
 * Service d'audit pré-backfill et dry-run partie double.
 *
 * Utilisé par BackfillPartieDoubleCommand en mode --dry-run.
 * Entièrement en lecture seule — aucune écriture en base.
 *
 * Step 32 : audit pour dry-run (nb Tx à convertir, ventilations invalides, modes non couverts).
 */
final class BackfillAuditor
{
    /** Matrice §4.3 — modes de paiement standard couverts par EcritureGenerator. */
    private const MODES_PAIEMENT_STANDARD = ['cheque', 'especes', 'virement', 'cb', 'prelevement'];

    /**
     * Produit le rapport d'audit pour un exercice donné.
     *
     * @return array{
     *     nb_transactions_a_convertir: int,
     *     ventilations_invalides: list<array{id: int, nom: string}>,
     *     modes_non_couverts: list<array{mode_paiement: string, count: int}>,
     *     modes_non_couverts_count: int,
     * }
     */
    public function auditer(int $associationId, int $annee): array
    {
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        // -- Nb transactions à convertir (equilibree=FALSE ou NULL) --
        // Les transactions à 0 € ne sont jamais convertibles (skip par design
        // dans TransactionConverter::convertir()) : on ne les compte pas.
        $nbAConvertir = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateDebut, $dateFin])
            ->where('montant_total', '!=', 0)
            ->where(function ($q) {
                $q->where('equilibree', false)
                    ->orWhereNull('equilibree');
            })
            ->count();

        // -- Lignes de ventilation que TransactionConverter skipperait --
        $ventilationsInvalides = DB::table('transaction_lignes as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->leftJoin('comptes as c', 'c.id', '=', 'tl.compte_id')
            ->where('t.association_id', $associationId)
            ->whereBetween('t.date', [$dateDebut, $dateFin])
            ->whereNull('t.deleted_at')
            ->whereNull('tl.deleted_at')
            ->where('tl.montant', '!=', 0)
            ->where(function ($query) use ($associationId): void {
                $query->whereNull('c.id')
                    ->orWhere('c.association_id', '!=', $associationId)
                    ->orWhereNotNull('c.deleted_at')
                    ->orWhere(function ($classe): void {
                        $classe->where('t.type', 'recette')
                            ->where('c.classe', '!=', 7);
                    })
                    ->orWhere(function ($classe): void {
                        $classe->where('t.type', 'depense')
                            ->where('c.classe', '!=', 6);
                    });
            })
            ->select('tl.id', 'tl.libelle')
            ->orderBy('tl.id')
            ->get()
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'nom' => (string) ($r->libelle ?? 'Ligne sans libellé'),
            ])
            ->all();

        // -- Modes de paiement non couverts dans l'exercice --
        $modesNonCouverts = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateDebut, $dateFin])
            ->whereNotNull('mode_paiement')
            ->whereNotIn('mode_paiement', self::MODES_PAIEMENT_STANDARD)
            ->select('mode_paiement', DB::raw('COUNT(*) as count'))
            ->groupBy('mode_paiement')
            ->orderBy('mode_paiement')
            ->get()
            ->map(fn ($r): array => [
                'mode_paiement' => (string) $r->mode_paiement,
                'count' => (int) $r->count,
            ])
            ->all();

        return [
            'nb_transactions_a_convertir' => $nbAConvertir,
            'ventilations_invalides' => $ventilationsInvalides,
            'modes_non_couverts' => $modesNonCouverts,
            'modes_non_couverts_count' => count($modesNonCouverts),
        ];
    }
}
