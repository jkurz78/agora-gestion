<?php

declare(strict_types=1);

namespace App\Services;

final class HelloAssoSyncResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<array{slug: string, type: string, manque: string, commandes: int}>  $formulairesNonConfigures
     */
    public function __construct(
        public readonly int $transactionsCreated = 0,
        public readonly int $transactionsUpdated = 0,
        public readonly int $lignesCreated = 0,
        public readonly int $lignesUpdated = 0,
        public readonly int $participantsCreated = 0,
        public readonly int $ordersSkipped = 0,
        public readonly array $errors = [],
        public readonly array $formulairesNonConfigures = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Des commandes ont-elles été écartées faute de configuration ?
     *
     * À distinguer d'`ordersSkipped`, qui agrège aussi les formulaires
     * délibérément marqués « ignorer » et les commandes déjà supprimées. Ici,
     * il s'agit d'argent réel dont l'écriture n'a pas été créée parce que le
     * formulaire n'a pas de compte ou pas d'opération.
     */
    public function aDesFormulairesNonConfigures(): bool
    {
        return count($this->formulairesNonConfigures) > 0;
    }

    /** Nombre de commandes écartées faute de configuration. */
    public function commandesNonConfigurees(): int
    {
        return array_sum(array_column($this->formulairesNonConfigures, 'commandes'));
    }

    public function totalTransactions(): int
    {
        return $this->transactionsCreated + $this->transactionsUpdated;
    }
}
