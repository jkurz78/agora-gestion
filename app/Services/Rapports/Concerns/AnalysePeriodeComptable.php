<?php

declare(strict_types=1);

namespace App\Services\Rapports\Concerns;

use App\Enums\JournalComptable;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Découpage d'une période comptable en « ouverture » et « mouvements »,
 * partagé par la balance et le grand livre pour qu'ils racontent la même
 * histoire à partir du même grand livre.
 *
 * Règle centrale — la coupure des à-nouveaux : une pièce AN *résume* tout
 * l'historique qui la précède. Les lignes antérieures à la dernière pièce AN
 * ne doivent donc jamais être recomptées, sinon le solde d'ouverture d'un
 * exercice suivant une clôture vaut le double du solde de clôture réel.
 */
trait AnalysePeriodeComptable
{
    /**
     * Date de la dernière pièce d'à-nouveaux antérieure ou égale à $debut.
     * Null tant qu'aucun exercice n'a été clôturé : l'ouverture est alors
     * dérivée de l'intégralité de l'historique.
     */
    private function dateCoupureANouveau(CarbonImmutable $debut): ?CarbonImmutable
    {
        $date = Transaction::query()
            ->where('journal', JournalComptable::AN->value)
            ->whereDate('date', '<=', $debut->toDateString())
            ->max('date');

        return $date !== null
            ? CarbonImmutable::parse((string) $date)->startOfDay()
            : null;
    }

    /**
     * Ouverture = solde reporté au premier jour de la période.
     *
     * Soit la pièce AN posée à $debut, soit — à défaut — le cumul des lignes
     * antérieures depuis la dernière coupure. Les comptes de résultat
     * (classes 6/7) repartent de zéro à chaque exercice et n'ont jamais
     * d'ouverture.
     */
    private function estOuverture(
        TransactionLigne $ligne,
        CarbonImmutable $debut,
        ?CarbonImmutable $coupure,
    ): bool {
        $date = $this->dateTransaction($ligne);
        if ($date === null || $this->estCompteResultat($ligne)) {
            return false;
        }

        if ($this->estAvantCoupure($date, $coupure)) {
            return false;
        }

        return $date->lt($debut)
            || ($date->equalTo($debut) && $this->estJournalANouveau($ligne));
    }

    /**
     * Mouvement = écriture de la période. Une pièce AN est une ouverture par
     * nature : elle n'est jamais comptée comme mouvement, y compris lorsque la
     * période enjambe une clôture (les mouvements qu'elle résume sont alors
     * déjà présents dans la fenêtre).
     */
    private function estMouvement(
        TransactionLigne $ligne,
        CarbonImmutable $debut,
        CarbonImmutable $fin,
        ?CarbonImmutable $coupure,
    ): bool {
        $date = $this->dateTransaction($ligne);
        if ($date === null || $this->estJournalANouveau($ligne)) {
            return false;
        }

        if ($this->estAvantCoupure($date, $coupure)) {
            return false;
        }

        return $date->betweenIncluded($debut, $fin);
    }

    private function estAvantCoupure(CarbonImmutable $date, ?CarbonImmutable $coupure): bool
    {
        return $coupure !== null && $date->lt($coupure);
    }

    private function dateTransaction(TransactionLigne $ligne): ?CarbonImmutable
    {
        $transaction = $ligne->transaction;

        return $transaction !== null
            ? CarbonImmutable::parse($transaction->date->toDateString())
            : null;
    }

    private function estJournalANouveau(TransactionLigne $ligne): bool
    {
        return $this->journal($ligne->transaction) === JournalComptable::AN->value;
    }

    private function journal(?Transaction $transaction): ?string
    {
        if ($transaction === null) {
            return null;
        }

        if ($transaction->journal instanceof JournalComptable) {
            return $transaction->journal->value;
        }

        return $transaction->journal !== null ? (string) $transaction->journal : null;
    }

    private function estCompteResultat(TransactionLigne $ligne): bool
    {
        return in_array((int) ($ligne->compte?->classe ?? 0), [6, 7], true);
    }

    /**
     * Borne la lecture du grand livre : rien avant la coupure n'est exploité,
     * inutile de le charger.
     *
     * @param  array<int, string>  $prefixes
     * @return Builder<TransactionLigne>
     */
    private function requeteLignes(
        CarbonImmutable $fin,
        ?CarbonImmutable $coupure,
        array $prefixes,
    ): Builder {
        return TransactionLigne::query()
            ->with(['compte', 'tiers', 'transaction'])
            ->whereHas('transaction', function (Builder $query) use ($fin, $coupure): void {
                $query->whereDate('date', '<=', $fin->toDateString());

                if ($coupure !== null) {
                    $query->whereDate('date', '>=', $coupure->toDateString());
                }
            })
            ->whereHas('compte', fn (Builder $query): Builder => $this->filtrerComptes($query, $prefixes));
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

    private function centimes(mixed $montant): int
    {
        return MontantDecimal::versCentimes((string) $montant);
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
