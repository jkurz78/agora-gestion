<?php

declare(strict_types=1);

use App\Models\Exercice;
use App\Services\ClotureCheckService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Régression de la recette du 2026-07-29 : l'exercice 2024 avait été clôturé
 * sans reprise initiale, l'à-nouveau portait 130 € au lieu de 2 518,82 € sur le
 * compte courant et le Livret Épargne — 24 010 € — était absent. Aucune garde ne
 * l'a signalé.
 */

beforeEach(function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);
});

it('refuse la clôture quand les soldes historiques ne sont pas dans le grand livre', function (): void {
    $resultat = app(ClotureCheckService::class)->executer(2024);

    $garde = collect($resultat->bloquants)->firstWhere('nom', 'Préalables comptables');

    expect($garde)->not->toBeNull()
        ->and($garde->ok)->toBeFalse()
        ->and($garde->message)->toContain('solde historique')
        ->and($garde->message)->not->toContain('artisan');
});

it('autorise la clôture une fois les soldes repris', function (): void {
    etatComptaCreerReprise($this, [$this->compte512X]);

    $resultat = app(ClotureCheckService::class)->executer(2024);

    $garde = collect($resultat->bloquants)->firstWhere('nom', 'Préalables comptables');

    expect($garde->ok)->toBeTrue();
});
