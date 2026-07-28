<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Enums\JournalComptable;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class GrandLivreBuilder
{
    /**
     * @param  array<int, string>  $prefixesComptes
     * @return array{date_debut: string, date_fin: string, prefixes_comptes: array<int, string>, comptes: list<array<string, mixed>>}
     */
    public function grandLivre(string $dateDebut, string $dateFin, array $prefixesComptes = []): array
    {
        $debut = CarbonImmutable::parse($dateDebut)->startOfDay();
        $fin = CarbonImmutable::parse($dateFin)->startOfDay();
        $prefixes = $this->normaliserPrefixes($prefixesComptes);

        $lignes = $this->lignesJusqua($fin, $prefixes);
        $comptes = [];

        foreach ($lignes as $ligne) {
            $transaction = $ligne->transaction;
            $compte = $ligne->compte;
            if ($transaction === null || $compte === null) {
                continue;
            }

            $compteId = (int) $compte->id;
            $comptes[$compteId] ??= $this->compteVide($compte);

            if ($this->estOuverture($ligne, $debut)) {
                $comptes[$compteId]['solde_ouverture_centimes'] += $this->montantSigneCentimes($ligne);

                continue;
            }

            if (! $this->estMouvement($ligne, $debut, $fin)) {
                continue;
            }

            $comptes[$compteId]['mouvement_debit_centimes'] += $this->centimes($ligne->debit);
            $comptes[$compteId]['mouvement_credit_centimes'] += $this->centimes($ligne->credit);
            $comptes[$compteId]['lignes'][] = $this->ligneGrandLivre($ligne);
        }

        $comptesFinalises = collect($comptes)
            ->map(fn (array $compte): array => $this->finaliserCompte($compte))
            ->filter(fn (array $compte): bool => $this->compteNonNul($compte))
            ->sort(fn (array $a, array $b): int => $this->comparerComptes($a, $b))
            ->values()
            ->all();

        return [
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'prefixes_comptes' => $prefixes,
            'comptes' => $comptesFinalises,
        ];
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return Collection<int, TransactionLigne>
     */
    private function lignesJusqua(CarbonImmutable $fin, array $prefixes): Collection
    {
        return TransactionLigne::query()
            ->with(['compte', 'tiers', 'transaction'])
            ->whereHas('transaction', fn (Builder $query): Builder => $query
                ->whereDate('date', '<=', $fin->toDateString()))
            ->whereHas('compte', fn (Builder $query): Builder => $this->filtrerComptes($query, $prefixes))
            ->get()
            ->sort(fn (TransactionLigne $a, TransactionLigne $b): int => $this->comparerLignes($a, $b))
            ->values();
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function filtrerComptes(Builder $query, array $prefixes): Builder
    {
        if ($prefixes === []) {
            return $query;
        }

        return $query->where(function (Builder $comptes) use ($prefixes): void {
            foreach ($prefixes as $prefixe) {
                $comptes->orWhere('numero_pcg', 'like', $prefixe.'%');
            }
        });
    }

    private function estOuverture(TransactionLigne $ligne, CarbonImmutable $debut): bool
    {
        $transaction = $ligne->transaction;
        if ($transaction === null) {
            return false;
        }

        if ($this->estCompteResultat($ligne)) {
            return false;
        }

        $date = CarbonImmutable::parse($transaction->date->toDateString());
        $journal = $this->journal($transaction);

        return $date->lt($debut)
            || ($date->equalTo($debut) && $journal === JournalComptable::AN->value);
    }

    private function estMouvement(TransactionLigne $ligne, CarbonImmutable $debut, CarbonImmutable $fin): bool
    {
        $transaction = $ligne->transaction;
        if ($transaction === null) {
            return false;
        }

        $date = CarbonImmutable::parse($transaction->date->toDateString());
        $journal = $this->journal($transaction);

        return $date->betweenIncluded($debut, $fin)
            && ! ($date->equalTo($debut) && $journal === JournalComptable::AN->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function compteVide(Compte $compte): array
    {
        return [
            'compte_id' => (int) $compte->id,
            'numero_compte' => (string) $compte->numero_pcg,
            'intitule_compte' => (string) $compte->intitule,
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
            'tiers_id' => $ligne->tiers_id !== null ? (int) $ligne->tiers_id : null,
            'tiers' => $ligne->tiers?->displayName(),
            'debit_centimes' => $this->centimes($ligne->debit),
            'credit_centimes' => $this->centimes($ligne->credit),
            'lettrage_code' => $ligne->lettrage_code,
            'solde_progressif_centimes' => 0,
        ];
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

    private function centimes(mixed $montant): int
    {
        return MontantDecimal::versCentimes((string) $montant);
    }

    private function estCompteResultat(TransactionLigne $ligne): bool
    {
        return in_array((int) ($ligne->compte?->classe ?? 0), [6, 7], true);
    }

    private function journal(Transaction $transaction): ?string
    {
        if ($transaction->journal instanceof JournalComptable) {
            return $transaction->journal->value;
        }

        return $transaction->journal !== null ? (string) $transaction->journal : null;
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
        return strcmp((string) $a['numero_compte'], (string) $b['numero_compte']);
    }

    private function comparerLignes(TransactionLigne $a, TransactionLigne $b): int
    {
        return strcmp((string) ($a->compte?->numero_pcg ?? ''), (string) ($b->compte?->numero_pcg ?? ''))
            ?: strcmp((string) ($a->transaction?->date?->toDateString() ?? ''), (string) ($b->transaction?->date?->toDateString() ?? ''))
            ?: ((int) ($a->transaction_id ?? 0) <=> (int) ($b->transaction_id ?? 0))
            ?: ((int) $a->id <=> (int) $b->id);
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return array<int, string>
     */
    private function normaliserPrefixes(array $prefixes): array
    {
        return collect($prefixes)
            ->map(fn (string $prefixe): string => trim($prefixe))
            ->filter(fn (string $prefixe): bool => $prefixe !== '')
            ->unique()
            ->values()
            ->all();
    }
}
