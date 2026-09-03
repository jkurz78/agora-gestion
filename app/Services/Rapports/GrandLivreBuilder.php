<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Rapports\Concerns\AnalysePeriodeComptable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class GrandLivreBuilder
{
    use AnalysePeriodeComptable;

    /**
     * @param  array<int, string>  $prefixesComptes
     * @param  bool  $uniquementNonSoldes  N'affiche que les comptes (et tiers
     *                                     auxiliaires) dont le solde de fin
     *                                     n'est pas nul.
     * @param  bool  $uniquementNonLettrees  Écarte les écritures lettrées, à
     *                                       l'ouverture comme en mouvement : ce
     *                                       qui reste est la position ouverte
     *                                       du compte (créances non encaissées,
     *                                       dettes non réglées).
     * @return array{date_debut: string, date_fin: string, prefixes_comptes: array<int, string>, uniquement_non_soldes: bool, uniquement_non_lettrees: bool, comptes: list<array<string, mixed>>}
     */
    public function grandLivre(
        string $dateDebut,
        string $dateFin,
        array $prefixesComptes = [],
        bool $uniquementNonSoldes = false,
        bool $uniquementNonLettrees = false,
    ): array {
        $debut = CarbonImmutable::parse($dateDebut)->startOfDay();
        $fin = CarbonImmutable::parse($dateFin)->startOfDay();
        $prefixes = $this->normaliserPrefixes($prefixesComptes);
        $coupure = $this->dateCoupureANouveau($debut);

        $lignes = $this->lignesJusqua($fin, $coupure, $prefixes);
        $comptes = [];

        foreach ($lignes as $ligne) {
            $transaction = $ligne->transaction;
            $compte = $ligne->compte;
            if ($transaction === null || $compte === null) {
                continue;
            }

            // Écarté dès la source : la ligne lettrée ne pèse alors ni sur
            // l'ouverture ni sur les mouvements, et le solde affiché vaut la
            // position ouverte du compte.
            if ($uniquementNonLettrees && $ligne->lettrage_code !== null) {
                continue;
            }

            // 401/411 : une entrée par tiers (compte auxiliaire), comme la balance.
            $cle = $this->cleRegroupement($ligne);
            $comptes[$cle] ??= $this->compteVide($ligne);

            if ($this->estOuverture($ligne, $debut, $coupure)) {
                $comptes[$cle]['solde_ouverture_centimes'] += $this->montantSigneCentimes($ligne);

                continue;
            }

            if (! $this->estMouvement($ligne, $debut, $fin, $coupure)) {
                continue;
            }

            $comptes[$cle]['mouvement_debit_centimes'] += $this->centimes($ligne->debit);
            $comptes[$cle]['mouvement_credit_centimes'] += $this->centimes($ligne->credit);
            $comptes[$cle]['lignes'][] = $this->ligneGrandLivre($ligne);
        }

        $comptesFinalises = collect($comptes)
            ->map(fn (array $compte): array => $this->finaliserCompte($compte))
            ->filter(fn (array $compte): bool => $this->compteNonNul($compte))
            ->filter(fn (array $compte): bool => ! $uniquementNonSoldes
                || (int) $compte['solde_fin_centimes'] !== 0)
            ->sort(fn (array $a, array $b): int => $this->comparerComptes($a, $b))
            ->values()
            ->all();

        return [
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'prefixes_comptes' => $prefixes,
            'uniquement_non_soldes' => $uniquementNonSoldes,
            'uniquement_non_lettrees' => $uniquementNonLettrees,
            'comptes' => $comptesFinalises,
        ];
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return Collection<int, TransactionLigne>
     */
    private function lignesJusqua(
        CarbonImmutable $fin,
        ?CarbonImmutable $coupure,
        array $prefixes,
    ): Collection {
        return $this->requeteLignes($fin, $coupure, $prefixes)
            ->get()
            ->sort(fn (TransactionLigne $a, TransactionLigne $b): int => $this->comparerLignes($a, $b))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function compteVide(TransactionLigne $ligne): array
    {
        /** @var Compte $compte */
        $compte = $ligne->compte;
        $tiersId = $this->tiersAuxiliaire($ligne);

        return [
            'compte_id' => (int) $compte->id,
            'numero_compte' => (string) $compte->numero_pcg,
            'intitule_compte' => (string) $compte->intitule,
            'tiers_id' => $tiersId !== 0 ? $tiersId : null,
            'tiers' => $tiersId !== 0 ? $ligne->tiers?->displayName() : null,
            'solde_ouverture_centimes' => 0,
            'mouvement_debit_centimes' => 0,
            'mouvement_credit_centimes' => 0,
            'solde_fin_centimes' => 0,
            'lignes' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneGrandLivre(TransactionLigne $ligne): array
    {
        /** @var Transaction $transaction */
        $transaction = $ligne->transaction;

        return [
            'ligne_id' => (int) $ligne->id,
            'transaction_id' => (int) $transaction->id,
            'date' => $transaction->date->toDateString(),
            'journal' => $this->journal($transaction),
            'numero_piece' => $transaction->numero_piece,
            'reference' => $transaction->reference,
            'libelle' => $ligne->libelle ?: $transaction->libelle,
            // `tiers_id` reste celui de la LIGNE : il porte la sémantique
            // auxiliaire (401/411) et sert au lettrage ; le remplir depuis la
            // transaction changerait le regroupement des comptes auxiliaires.
            'tiers_id' => $ligne->tiers_id !== null ? (int) $ligne->tiers_id : null,
            // Le LIBELLÉ, lui, se replie sur le tiers de la transaction. Une
            // ligne de classe 6 ou 7 ne porte jamais de tiers propre — mesuré
            // sur la base : 0 ligne sur 316 — alors que sa transaction en porte
            // un dans 316 cas sur 316. Sans ce repli, la colonne reste vide là
            // où l'information existe, et on ne sait pas de qui vient la
            // recette ni à qui la dépense a été payée.
            'tiers' => $ligne->tiers?->displayName() ?? $transaction->tiers?->displayName(),
            'mode_paiement' => $this->modePaiement($transaction),
            'justificatif_url' => $this->urlJustificatif($ligne, $transaction),
            'debit_centimes' => $this->centimes($ligne->debit),
            'credit_centimes' => $this->centimes($ligne->credit),
            'lettrage_code' => $ligne->lettrage_code,
            'solde_progressif_centimes' => 0,
        ];
    }

    private function modePaiement(Transaction $transaction): ?string
    {
        $mode = $transaction->mode_paiement;

        if ($mode instanceof ModePaiement) {
            return $mode->label();
        }

        return $mode !== null ? (string) $mode : null;
    }

    /**
     * Lien vers le justificatif : celui de la ligne s'il existe (ventilation
     * détaillée), sinon celui porté par la transaction.
     */
    private function urlJustificatif(TransactionLigne $ligne, Transaction $transaction): ?string
    {
        if ($ligne->piece_jointe_path !== null) {
            return route('comptabilite.transactions.piece-jointe-ligne', [
                'transaction' => (int) $transaction->id,
                'ligne' => (int) $ligne->id,
            ]);
        }

        return $transaction->piece_jointe_path !== null
            ? route('transactions.piece-jointe', ['transaction' => (int) $transaction->id])
            : null;
    }

    /**
     * @param  array<string, mixed>  $compte
     * @return array<string, mixed>
     */
    private function finaliserCompte(array $compte): array
    {
        $solde = (int) $compte['solde_ouverture_centimes'];

        $lignes = collect($compte['lignes'])
            ->sortBy(fn (array $ligne): array => [
                $ligne['date'],
                $ligne['transaction_id'],
                $ligne['ligne_id'],
            ])
            ->map(function (array $ligne) use (&$solde): array {
                $solde += (int) $ligne['debit_centimes'] - (int) $ligne['credit_centimes'];
                $ligne['solde_progressif_centimes'] = $solde;

                return $ligne;
            })
            ->values()
            ->all();

        $compte['lignes'] = $lignes;
        $compte['solde_fin_centimes'] = $solde;

        return $compte;
    }

    /**
     * @param  array<string, mixed>  $compte
     */
    private function compteNonNul(array $compte): bool
    {
        return (int) $compte['solde_ouverture_centimes'] !== 0
            || (int) $compte['mouvement_debit_centimes'] !== 0
            || (int) $compte['mouvement_credit_centimes'] !== 0
            || $compte['lignes'] !== [];
    }

    private function montantSigneCentimes(TransactionLigne $ligne): int
    {
        return $this->centimes($ligne->debit) - $this->centimes($ligne->credit);
    }

    /**
     * Trie les numéros de comptes comme des codes comptables, et non comme
     * des nombres : 5112 doit précéder 530.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function comparerComptes(array $a, array $b): int
    {
        return strcmp((string) $a['numero_compte'], (string) $b['numero_compte'])
            ?: strcmp((string) ($a['tiers'] ?? ''), (string) ($b['tiers'] ?? ''));
    }

    private function comparerLignes(TransactionLigne $a, TransactionLigne $b): int
    {
        return strcmp((string) ($a->compte?->numero_pcg ?? ''), (string) ($b->compte?->numero_pcg ?? ''))
            ?: strcmp((string) ($a->transaction?->date?->toDateString() ?? ''), (string) ($b->transaction?->date?->toDateString() ?? ''))
            ?: ((int) ($a->transaction_id ?? 0) <=> (int) ($b->transaction_id ?? 0))
            ?: ((int) $a->id <=> (int) $b->id);
    }
}
