<?php

declare(strict_types=1);

use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('seeds a V5-ready database whose demo transactions are balanced', function (): void {
    expect(Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']))
        ->toBe(0, Artisan::output());

    $associationId = (int) TenantContext::currentId();
    expect(DB::table('transactions')->where('association_id', $associationId)->count())
        ->toBeGreaterThan(0);

    expect(DB::table('transaction_lignes as tl')
        ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
        ->where('t.association_id', $associationId)
        ->select('tl.transaction_id')
        ->groupBy('tl.transaction_id')
        ->havingRaw('ROUND(SUM(tl.debit), 2) <> ROUND(SUM(tl.credit), 2)')
        ->count())->toBe(0);

    expect(Artisan::call('compta:smoke-test-v5', ['--asso' => [$associationId]]))
        ->toBe(0, Artisan::output());
});
