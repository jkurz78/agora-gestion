<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Extourne;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;

test('Extourne extends TenantModel — tenant scope fail-closed', function (): void {
    $associationA = Association::factory()->create();
    TenantContext::boot($associationA);
    $extourneA = Extourne::factory()->create();

    TenantContext::clear();
    expect(Extourne::query()->count())->toBe(0);

    TenantContext::boot($associationA);
    expect(Extourne::query()->count())->toBe(1);
    expect(Extourne::query()->first()->id)->toBe($extourneA->id);

    $associationB = Association::factory()->create();
    TenantContext::boot($associationB);
    expect(Extourne::query()->count())->toBe(0);
});

test('Extourne origine relation returns origin Transaction', function (): void {
    $extourne = Extourne::factory()->create();

    expect($extourne->origine)->toBeInstanceOf(Transaction::class);
    expect($extourne->origine->id)->toBe($extourne->transaction_origine_id);
});

test('Extourne extourne relation returns mirror Transaction', function (): void {
    $extourne = Extourne::factory()->create();

    expect($extourne->extourne)->toBeInstanceOf(Transaction::class);
    expect($extourne->extourne->id)->toBe($extourne->transaction_extourne_id);
});

test('Extourne lettrage relation returns RapprochementBancaire or null', function (): void {
    $sansLettrage = Extourne::factory()->create(['rapprochement_lettrage_id' => null]);
    expect($sansLettrage->lettrage)->toBeNull();

    $rapprochement = RapprochementBancaire::factory()->create();
    $avecLettrage = Extourne::factory()->create(['rapprochement_lettrage_id' => $rapprochement->id]);
    expect($avecLettrage->lettrage)->toBeInstanceOf(RapprochementBancaire::class);
    expect($avecLettrage->lettrage->id)->toBe($rapprochement->id);
});

test('Extourne creator relation returns User', function (): void {
    $extourne = Extourne::factory()->create();

    expect($extourne->creator)->toBeInstanceOf(User::class);
    expect($extourne->creator->id)->toBe($extourne->created_by);
});

test('Extourne soft deletes are active', function (): void {
    $extourne = Extourne::factory()->create();
    $id = $extourne->id;

    $extourne->delete();

    expect(Extourne::query()->find($id))->toBeNull();
    expect(Extourne::withTrashed()->find($id))->not->toBeNull();
    expect(Extourne::withTrashed()->find($id)->trashed())->toBeTrue();
});

test('Extourne factory creates coherent origin + mirror with opposite signs and same tenant', function (): void {
    $extourne = Extourne::factory()->create();

    expect($extourne->origine->association_id)->toBe($extourne->extourne->association_id);
    expect($extourne->origine->association_id)->toBe($extourne->association_id);

    expect((float) $extourne->origine->montant_total)->toBeGreaterThan(0);
    expect((float) $extourne->extourne->montant_total)->toBeLessThan(0);
    expect((float) $extourne->extourne->montant_total)->toBe(-1 * (float) $extourne->origine->montant_total);
});

test('Extourne auto-fills association_id from tenant context on creating', function (): void {
    $extourne = Extourne::factory()->create();

    expect($extourne->association_id)->toBe(TenantContext::currentId());
});
