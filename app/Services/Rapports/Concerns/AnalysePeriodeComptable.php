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
     * Clé de regroupement d'une ligne dans un état comptable.
     *
     * Les comptes 401 et 411 sont des comptes *collectifs* : le tiers y tient
     * lieu de compte auxiliaire. Le détail par tiers distingue donc la balance
     * auxiliaire (une entrée par tiers) de la balance générale (le compte
     * collectif en une seule ligne, document de référence du bilan). Le grand
     * livre, lui, est auxiliaire par nature : il détaille toujours.
     */
    private function cleRegroupement(TransactionLigne $ligne, bool $detailParTiers = true): string
    {
        return ((int) $ligne->compte_id).'|'.$this->tiersAuxiliaire($ligne, $detailParTiers);
    }

    /**
     * Identifiant du tiers auxiliaire, ou 0 si le compte n'est pas collectif
     * (ou si l'état est présenté sans détail par tiers).
     */
    private function tiersAuxiliaire(TransactionLigne $ligne, bool $detailParTiers = true): int
    {
        if (! $detailParTiers) {
            return 0;
        }

        $estCollectif = in_array($ligne->compte?->numero_pcg ?? '', ['401', '411'], true);

        return $estCollectif && $ligne->tiers_id !== null ? (int) $ligne->tiers_id : 0;
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
            // `transaction.tiers` est préchargé pour le grand livre, dont la
            // colonne Tiers se replie sur celui de la transaction quand la
            // ligne n'en porte pas (classes 6 et 7, où c'est toujours le cas).
            // Sans ce préchargement, c'est une requête par ligne affichée.
            ->with(['compte', 'tiers', 'transaction', 'transaction.tiers'])
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
