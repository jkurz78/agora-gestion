<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\UsageCompte;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('drops the legacy accounting schema and renames usages', function (): void {
    expect(Schema::hasTable('usages_comptes'))->toBeTrue()
        ->and(Schema::hasTable('usages_sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('categories'))->toBeFalse()
        ->and(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeFalse()
        ->and(Schema::hasColumn('comptes', 'categorie_id'))->toBeFalse();
});

it('enforces one usage per tenant account and usage', function (): void {
    $compte = Compte::factory()->create();
    UsageCompte::factory()->for($compte)->create(['usage' => UsageComptable::Don]);

    expect(fn () => UsageCompte::factory()->for($compte)->create([
        'usage' => UsageComptable::Don,
    ]))->toThrow(QueryException::class);
});
