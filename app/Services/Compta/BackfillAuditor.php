<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Services\ExerciceService;
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
        // Bornes issues du paramétrage du tenant : le mois de début d'exercice
        // appartient à l'association, pas au code.
        $range = app(ExerciceService::class)->dateRange($annee);
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        // -- Nb transactions à convertir (equilibree=FALSE ou NULL) --
        // Pas de filtre sur montant_total : une transaction à 0 € N'EST PLUS
        // systématiquement inconvertible depuis que TransactionConverter::convertir()
        // ne skip plus sur ce critère (une gratuité intégrale — ventilations qui se
        // compensent, ex. 706 crédit / 709A débit — est désormais convertie). Le vrai
        // critère de convertibilité est « au moins une ligne de ventilation valide »,
        // trop coûteux à répliquer exactement ici en SQL (il faudrait rejouer la
        // validation de classe de compte ligne par ligne comme le fait le converter) :
        // on préfère sur-compter légèrement ce rapport de dry-run plutôt que de
        // sous-compter et laisser l'opérateur croire qu'il y a moins de transactions
        // à traiter qu'il n'y en a réellement.
        $nbAConvertir = DB::table('transactions')
            ->where('association_id', $associationId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateDebut, $dateFin])
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
            ->where(function ($query) use ($associationId): void {
                $query->whereNull('c.id')
                    ->orWhere('c.association_id', '!=', $associationId)
                    ->orWhereNotNull('c.deleted_at')
                    ->orWhere(function ($classe): void {
                        $classe->where('t.type', 'recette')
                            ->where('c.classe', '!=', 7);
                    })
                    ->orWhere(function ($classe): void {
                        $classe->where('t.type', '!=', 'recette')
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
