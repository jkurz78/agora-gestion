<?php

declare(strict_types=1);

use App\Enums\TypeRapprochement;
use App\Models\RapprochementBancaire;
use Illuminate\Support\Facades\Schema;

test('extournes table has expected columns', function (): void {
    expect(Schema::hasTable('extournes'))->toBeTrue();

    $columns = [
        'id',
        'transaction_origine_id',
        'transaction_extourne_id',
        'rapprochement_lettrage_id',
        'association_id',
        'created_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('extournes', $column))
            ->toBeTrue("Column extournes.{$column} is missing");
    }
});

test('transactions table has extournee_at column', function (): void {
    expect(Schema::hasColumn('transactions', 'extournee_at'))->toBeTrue();
});

test('rapprochements_bancaires table has type column', function (): void {
    expect(Schema::hasColumn('rapprochements_bancaires', 'type'))->toBeTrue();
});

test('rapprochements_bancaires created without explicit type defaults to bancaire', function (): void {
    $rapprochement = RapprochementBancaire::factory()->create();

    $raw = DB::table('rapprochements_bancaires')->where('id', $rapprochement->id)->first();

    expect($raw->type)->toBe('bancaire');
});

test('TypeRapprochement enum has Bancaire and Lettrage cases with labels', function (): void {
    expect(TypeRapprochement::Bancaire->value)->toBe('bancaire');
    expect(TypeRapprochement::Lettrage->value)->toBe('lettrage');

    expect(TypeRapprochement::Bancaire->label())->toBe('Bancaire');
    expect(TypeRapprochement::Lettrage->label())->toBe('Lettrage');
});

test('RapprochementBancaire model casts type to TypeRapprochement enum', function (): void {
    $rapprochement = RapprochementBancaire::factory()->create();

    expect($rapprochement->type)->toBeInstanceOf(TypeRapprochement::class);
    expect($rapprochement->type)->toBe(TypeRapprochement::Bancaire);
});

test('RapprochementBancaire isLettrage helper returns true only when type Lettrage', function (): void {
    $bancaire = RapprochementBancaire::factory()->create();
    $lettrage = RapprochementBancaire::factory()->create(['type' => TypeRapprochement::Lettrage]);

    expect($bancaire->isLettrage())->toBeFalse();
    expect($lettrage->isLettrage())->toBeTrue();
});

test('RapprochementBancaire isBancaire helper returns true only when type Bancaire', function (): void {
    $bancaire = RapprochementBancaire::factory()->create();
    $lettrage = RapprochementBancaire::factory()->create(['type' => TypeRapprochement::Lettrage]);

    expect($bancaire->isBancaire())->toBeTrue();
    expect($lettrage->isBancaire())->toBeFalse();
});

test('extournee_at index exists on transactions', function (): void {
    $indexes = collect(DB::select("PRAGMA index_list('transactions')"))
        ->pluck('name')
        ->all();

    expect($indexes)->toContain('transactions_extournee_at_index');
});

test('type index exists on rapprochements_bancaires', function (): void {
    $indexes = collect(DB::select("PRAGMA index_list('rapprochements_bancaires')"))
        ->pluck('name')
        ->all();

    expect($indexes)->toContain('rapprochements_bancaires_type_index');
});

test('extournes UNIQUE constraints on transaction_origine_id and transaction_extourne_id', function (): void {
    $indexes = collect(DB::select("PRAGMA index_list('extournes')"))
        ->keyBy('name');

    expect($indexes->has('extournes_transaction_origine_id_unique'))->toBeTrue();
    expect($indexes->has('extournes_transaction_extourne_id_unique'))->toBeTrue();

    expect((bool) $indexes->get('extournes_transaction_origine_id_unique')->unique)->toBeTrue();
    expect((bool) $indexes->get('extournes_transaction_extourne_id_unique')->unique)->toBeTrue();
});
