<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Portail\Providers\MesDonsProvider;

it('returns DTO when tiers has at least 1 don', function (): void {
    $tiers = Tiers::factory()->create();
    $compte = Compte::factory()->pourDons()->create();

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'credit' => 50.0,
        'montant' => 50.0,
    ]);

    $provider = new MesDonsProvider;
    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('mes-dons')
        ->and($dto->label)->toBe('Mes dons')
        ->and($dto->routeName)->toBe('portail.mes-dons')
        ->and($dto->icon)->toBe('bi-gift')
        ->and($dto->ordre)->toBe(70)
        ->and($dto->groupe)->toBe('Ma vie de membre')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('returns null when tiers has no don', function (): void {
    $tiers = Tiers::factory()->create();
    $provider = new MesDonsProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});

it('returns null when tiers has recette but not on a don compte', function (): void {
    $tiers = Tiers::factory()->create();
    $compte = Compte::factory()->create(); // no Don usage

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'credit' => 50.0,
        'montant' => 50.0,
    ]);

    $provider = new MesDonsProvider;
    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});
