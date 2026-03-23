<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\HelloAssoTiersResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts unique persons from orders by nom+prénom', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
        ],
        [
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Marie', 'lastName' => 'Martin']],
            ],
        ],
        [
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(2);
    $names = collect($persons)->map(fn ($p) => $p['lastName'])->sort()->values()->all();
    expect($names)->toBe(['Dupont', 'Martin']);
});

it('attaches payer email when beneficiary name matches payer', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['email'])->toBe('jean@test.com');
});

it('does not attach payer email when beneficiary differs from payer', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'Parent', 'lastName' => 'Dupont', 'email' => 'parent@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Enfant', 'lastName' => 'Dupont']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['firstName'])->toBe('Enfant');
    expect($persons[0]['email'])->toBeNull();
});

it('uses payer as fallback when order has no items', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'Paul', 'lastName' => 'Durand', 'email' => 'paul@test.com'],
            'items' => [],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['firstName'])->toBe('Paul');
    expect($persons[0]['email'])->toBe('paul@test.com');
});

it('skips items with no user or empty lastName', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'X', 'lastName' => 'Y', 'email' => 'x@test.com'],
            'items' => [
                ['name' => 'Item sans user'],
                ['user' => ['firstName' => 'A', 'lastName' => '']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(0);
});

it('marks already linked tiers (est_helloasso + same nom+prénom)', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'est_helloasso' => true]);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['linked'])->toHaveCount(1);
    expect($result['linked'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'])->toHaveCount(0);
});

it('suggests match by nom+prénom for non-helloasso tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'est_helloasso' => false]);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => null],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('nom');
});

it('suggests match by email when available', function () {
    $tiers = Tiers::factory()->create(['email' => 'jean@test.com', 'nom' => 'Autre', 'prenom' => 'Nom']);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('email');
});

it('extracts per-item beneficiaries when items have distinct users', function () {
    $orders = [
        [
            'payer' => ['firstName' => 'Parent', 'lastName' => 'Dupont', 'email' => 'parent@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Enfant1', 'lastName' => 'Dupont']],
                ['user' => ['firstName' => 'Enfant2', 'lastName' => 'Dupont']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(2);
    $firstNames = collect($persons)->pluck('firstName')->sort()->values()->all();
    expect($firstNames)->toBe(['Enfant1', 'Enfant2']);
});

it('attaches payer address data to all beneficiaries', function () {
    $orders = [
        [
            'payer' => [
                'firstName' => 'Parent', 'lastName' => 'Dupont', 'email' => 'parent@test.com',
                'address' => '5 rue A', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'FRA',
            ],
            'items' => [
                ['user' => ['firstName' => 'Enfant', 'lastName' => 'Dupont']],
            ],
        ],
    ];

    $resolver = new HelloAssoTiersResolver;
    $persons = $resolver->extractPersons($orders);

    expect($persons[0]['address'])->toBe('5 rue A');
    expect($persons[0]['city'])->toBe('Lyon');
    expect($persons[0]['zipCode'])->toBe('69001');
    expect($persons[0]['country'])->toBe('FRA');
});

it('returns empty suggestions when no match', function () {
    $persons = [
        ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => null],
    ];

    $resolver = new HelloAssoTiersResolver;
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(0);
});
