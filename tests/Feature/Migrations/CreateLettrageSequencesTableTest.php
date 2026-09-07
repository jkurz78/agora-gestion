<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('crée une séquence de lettrage unique par tenant et compte', function (): void {
    expect(Schema::hasColumns('lettrage_sequences', [
        'association_id',
        'compte_id',
        'next_value',
    ]))->toBeTrue();

    $association = Association::firstOrFail();
    // Le compte doit réellement exister : lettrage_sequences.compte_id porte
    // une FK vers comptes, que SQLite n'applique pas mais que MySQL/MariaDB si.
    $compteId = Compte::factory()->create(['association_id' => $association->id])->id;

    DB::table('lettrage_sequences')->insert([
        'association_id' => (int) $association->id,
        'compte_id' => (int) $compteId,
        'next_value' => 1,
    ]);

    expect(fn () => DB::table('lettrage_sequences')->insert([
        'association_id' => (int) $association->id,
        'compte_id' => (int) $compteId,
        'next_value' => 2,
    ]))->toThrow(QueryException::class);
});
