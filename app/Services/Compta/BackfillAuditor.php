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
 * Step 32 : audit pour dry-run (nb Tx à convertir, SC sans code_cerfa, modes non couverts).
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
     *     sc_sans_code_cerfa: list<array{id: int, nom: string}>,
     *     modes_non_couverts: list<array{mode_paiement: string, count: int}>,
     *     modes_non_couverts_count: int,
     * }
     */
    public function auditer(int $associationId, int $annee): array
    {
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        // -- Nb transactions à convertir (equilibree=FALSE ou NULL) --
        $nbAConvertir = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateDebut, $dateFin])
            ->where(function ($q) {
                $q->where('equilibree', false)
                    ->orWhereNull('equilibree');
            })
            ->count();

        // -- SC sans code_cerfa dans ce tenant --
        $scSansCode = DB::table('sous_categories')
            ->where('association_id', $associationId)
            ->whereNull('code_cerfa')
            ->select('id', 'nom')
            ->orderBy('id')
            ->get()
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'nom' => (string) $r->nom,
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
            'sc_sans_code_cerfa' => $scSansCode,
            'modes_non_couverts' => $modesNonCouverts,
            'modes_non_couverts_count' => count($modesNonCouverts),
        ];
    }
}
