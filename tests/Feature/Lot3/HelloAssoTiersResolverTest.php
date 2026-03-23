<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\HelloAssoTiersResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts unique persons from orders by email', function () {
    $orders = [
        ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'], 'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com']],
        ['user' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'], 'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com']],
        ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'], 'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com']],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(2);
    expect(collect($persons)->pluck('email')->sort()->values()->all())->toBe(['jean@test.com', 'marie@test.com']);
});

it('uses payer when user is null', function () {
    $orders = [
        ['user' => null, 'payer' => ['firstName' => 'Paul', 'lastName' => 'Durand', 'email' => 'paul@test.com']],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['email'])->toBe('paul@test.com');
    expect($persons[0]['firstName'])->toBe('Paul');
});

it('skips orders with no email', function () {
    $orders = [
        ['user' => ['firstName' => 'X', 'lastName' => 'Y', 'email' => ''], 'payer' => ['firstName' => 'X', 'lastName' => 'Y', 'email' => '']],
        ['user' => null, 'payer' => null],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(0);
});

it('marks already linked tiers (est_helloasso + same email)', function () {
    $tiers = Tiers::factory()->create(['email' => 'jean@test.com', 'est_helloasso' => true]);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['linked'])->toHaveCount(1);
    expect($result['linked'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'])->toHaveCount(0);
});

it('suggests match by email for non-helloasso tiers', function () {
    $tiers = Tiers::factory()->create(['email' => 'jean@test.com', 'est_helloasso' => false, 'nom' => 'Dupont', 'prenom' => 'Jean']);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('email');
});

it('suggests match by name+prenom', function () {
    $tiers = Tiers::factory()->create(['email' => 'autre@test.com', 'nom' => 'Dupont', 'prenom' => 'Jean']);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean-nouveau@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('nom');
});

it('extracts per-item beneficiaries when items have distinct users', function () {
    $orders = [
        [
            'user' => ['firstName' => 'Parent', 'lastName' => 'Dupont', 'email' => 'parent@test.com'],
            'payer' => ['firstName' => 'Parent', 'lastName' => 'Dupont', 'email' => 'parent@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Enfant1', 'lastName' => 'Dupont', 'email' => 'enfant1@test.com']],
                ['user' => ['firstName' => 'Enfant2', 'lastName' => 'Dupont', 'email' => 'enfant2@test.com']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(2);
    $emails = collect($persons)->pluck('email')->sort()->values()->all();
    expect($emails)->toBe(['enfant1@test.com', 'enfant2@test.com']);
});

it('falls back to order-level user when items have no user', function () {
    $orders = [
        [
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['name' => 'Adhésion'],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['email'])->toBe('jean@test.com');
});

it('returns empty suggestions when no match', function () {
    $persons = [
        ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(0);
});
