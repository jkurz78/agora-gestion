<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has helloasso_cashout_id column on virements_internes table', function (): void {
    expect(Schema::hasColumn('virements_internes', 'helloasso_cashout_id'))->toBeTrue();
});

it('can store helloasso_cashout_id on a virement interne', function (): void {
    $user = User::factory()->create();
    $source = CompteBancaire::factory()->create();
    $dest = CompteBancaire::factory()->create();

    $virement = VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => '500.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 123456,
    ]);

    expect($virement->refresh()->helloasso_cashout_id)->toBe(123456);
});

it('allows nullable helloasso_cashout_id', function (): void {
    $user = User::factory()->create();
    $source = CompteBancaire::factory()->create();
    $dest = CompteBancaire::factory()->create();

    $virement = VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => '500.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
    ]);

    expect($virement->refresh()->helloasso_cashout_id)->toBeNull();
});

it('rejects duplicate helloasso_cashout_id values', function (): void {
    $user = User::factory()->create();
    $source = CompteBancaire::factory()->create();
    $dest = CompteBancaire::factory()->create();

    VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => '500.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 999,
    ]);

    VirementInterne::create([
        'date' => '2025-11-01',
        'montant' => '300.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 999,
    ]);
})->throws(QueryException::class);
