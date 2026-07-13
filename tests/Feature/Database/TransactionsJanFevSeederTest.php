<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\ComptesProvisioningService;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Tenant\TenantContext;
use Database\Seeders\TransactionsJanFevSeeder;
use Illuminate\Support\Facades\DB;

it('seeds 80 tenant-scoped transactions with valid ventilations and balanced ledgers', function (): void {
    $association = TenantContext::current();
    app(DefaultChartOfAccountsService::class)->applyTo($association);
    CompteBancaire::factory()->create();
    app(ComptesProvisioningService::class)->provisionAll();

    $obsoleteTiers = Tiers::factory()->count(6)->create();
    $obsoleteTiers->each->delete();
    Tiers::factory()->count(3)->create();

    $obsoleteOperations = Operation::factory()->count(2)->create();
    $obsoleteOperations->each->delete();
    Operation::factory()->count(2)->create();

    Association::query()->whereKeyNot($association->id)->delete();
    TenantContext::clear();

    (new TransactionsJanFevSeeder)->run();

    $transactions = Transaction::query()->with('lignes.compte')->get();
    expect($transactions)->toHaveCount(80);

    foreach ($transactions as $transaction) {
        expect($transaction->tiers_id)->not->toBeNull();
        expect(round((float) $transaction->lignes->sum('debit'), 2))
            ->toBe(round((float) $transaction->lignes->sum('credit'), 2));

        $classeVentilation = $transaction->type === TypeTransaction::Depense ? 6 : 7;
        expect($transaction->lignes->contains(
            fn ($ligne): bool => $ligne->compte?->classe === $classeVentilation
        ))->toBeTrue();
    }

    expect(DB::table('transaction_lignes as tl')
        ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
        ->join('comptes as c', 'c.id', '=', 'tl.compte_id')
        ->whereColumn('c.association_id', '!=', 't.association_id')
        ->count())->toBe(0);

    expect(Compte::whereIn('numero_pcg', ['5112', '530'])->count())->toBe(2);
});
