<?php

declare(strict_types=1);

use App\Models\Association;
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
    $compteId = DB::table('comptes')
        ->where('association_id', (int) $association->id)
        ->value('id');

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
