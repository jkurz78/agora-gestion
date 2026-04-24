<?php

declare(strict_types=1);

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Models\TenantModel;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

it('extends TenantModel', function () {
    expect(FacturePartenaireDeposee::class)->toExtend(TenantModel::class);
});

it('scope fail-closed: query returns no rows when TenantContext is not booted', function () {
    // Create a record while context IS booted (global bootstrap already booted one)
    $record = FacturePartenaireDeposee::factory()->create();
    expect($record->id)->not->toBeNull();

    // Clear context — scope should now be fail-closed
    TenantContext::clear();

    $count = FacturePartenaireDeposee::count();
    expect($count)->toBe(0);
});

it('retrieves record after TenantContext is booted for its association', function () {
    $record = FacturePartenaireDeposee::factory()->create();

    // withoutGlobalScopes to confirm the row exists in DB
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);
    expect($fresh)->not->toBeNull();

    // Re-boot context with the same association so the scope matches
    TenantContext::boot($fresh->association);

    $found = FacturePartenaireDeposee::find($record->id);
    expect($found)->not->toBeNull();
    expect((int) $found->id)->toBe((int) $record->id);
});

it('casts date_facture to a Carbon date', function () {
    $record = FacturePartenaireDeposee::factory()->create(['date_facture' => '2026-01-15']);
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);

    expect($fresh->date_facture)->toBeInstanceOf(Carbon::class);
    expect($fresh->date_facture->format('Y-m-d'))->toBe('2026-01-15');
});

it('casts traitee_at to a Carbon datetime when set', function () {
    $now = now()->startOfSecond();
    $record = FacturePartenaireDeposee::factory()->create(['traitee_at' => $now]);
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);

    expect($fresh->traitee_at)->toBeInstanceOf(Carbon::class);
});

it('traitee_at is null when not set', function () {
    $record = FacturePartenaireDeposee::factory()->create(['traitee_at' => null]);
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);

    expect($fresh->traitee_at)->toBeNull();
});

it('casts statut to StatutFactureDeposee enum', function (string $raw, StatutFactureDeposee $expected) {
    $record = FacturePartenaireDeposee::factory()->create(['statut' => $raw]);
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);

    expect($fresh->statut)->toBeInstanceOf(StatutFactureDeposee::class);
    expect($fresh->statut)->toBe($expected);
})->with([
    'soumise' => ['soumise',  StatutFactureDeposee::Soumise],
    'traitee' => ['traitee',  StatutFactureDeposee::Traitee],
    'rejetee' => ['rejetee',  StatutFactureDeposee::Rejetee],
]);

it('statut defaults to Soumise on creation', function () {
    $record = FacturePartenaireDeposee::factory()->create();
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);

    expect($fresh->statut)->toBe(StatutFactureDeposee::Soumise);
});

it('relation tiers() returns BelongsTo Tiers', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $record = FacturePartenaireDeposee::factory()->create(['tiers_id' => $tiers->id]);

    expect($record->tiers())->toBeInstanceOf(BelongsTo::class);
    $related = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id)->tiers;
    expect($related)->not->toBeNull();
    expect((int) $related->id)->toBe((int) $tiers->id);
});

it('relation transaction() returns BelongsTo Transaction (nullable)', function () {
    $record = FacturePartenaireDeposee::factory()->create(['transaction_id' => null]);

    expect($record->transaction())->toBeInstanceOf(BelongsTo::class);
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);
    expect($fresh->transaction)->toBeNull();
});

it('relation transaction() resolves when transaction_id is set', function () {
    $transaction = Transaction::factory()->create();
    $record = FacturePartenaireDeposee::factory()->create(['transaction_id' => $transaction->id]);

    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($record->id);
    expect($fresh->transaction)->not->toBeNull();
    expect((int) $fresh->transaction->id)->toBe((int) $transaction->id);
});
