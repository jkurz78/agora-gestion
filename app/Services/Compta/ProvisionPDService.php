<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;

final class ProvisionPDService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
    ) {}

    public function generer(Provision $provision): void
    {
        throw new \LogicException('ProvisionPDService::generer() est réécrit en Task 2 du lot rattachement.');
    }

    public function supprimer(Provision $provision): void
    {
        Transaction::where('provision_id', (int) $provision->id)->each(function (Transaction $tx): void {
            TransactionLigne::where('transaction_id', $tx->id)->delete();
            $tx->forceDelete();
        });
    }
}
