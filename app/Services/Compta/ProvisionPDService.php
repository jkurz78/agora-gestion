<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Support\Facades\DB;

final class ProvisionPDService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
    ) {}

    public function generer(Provision $provision): void
    {
        DB::transaction(function () use ($provision): void {
            $this->supprimer($provision);

            $dotation = $this->ecritureGenerator->pourProvisionDotation($provision);
            PartieDoubleGuard::assertComplete($dotation);

            $extourne = $this->ecritureGenerator->pourProvisionExtourne($provision);
            PartieDoubleGuard::assertComplete($extourne);
        });
    }

    public function supprimer(Provision $provision): void
    {
        Transaction::where('provision_id', (int) $provision->id)->each(function (Transaction $tx): void {
            TransactionLigne::where('transaction_id', $tx->id)->delete();
            $tx->forceDelete();
        });
    }
}
