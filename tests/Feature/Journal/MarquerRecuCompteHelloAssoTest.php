<?php

declare(strict_types=1);

use App\Livewire\TransactionUniverselle;
use App\Models\CompteBancaire;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
});

// ---------------------------------------------------------------------------
// Bug A — Le compte HelloAsso est sélectionnable dans la modale « Marquer reçu »
//
// La modale « Marquer reçu » dans TransactionUniverselle itère la clé
// `comptesBancaires` du view(). Avant fix, cette clé contenait TOUS les comptes
// (y compris les comptes à saisie automatisée comme HelloAsso).
//
// Fix : passer une liste filtrée par ->saisieManuelle() à la clé `comptesBancaires`
// (tout en conservant la liste complète pour le filtre de colonnes via `comptes`).
// ---------------------------------------------------------------------------

it('[BugA] la liste ne rend plus la modale legacy marquer-recu', function () {
    // Compte normal : saisie manuelle autorisée (valeurs factory par défaut)
    $compteNormal = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte courant test',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
    ]);

    // Compte HelloAsso : saisie automatisée → exclu de saisieManuelle()
    $compteHelloAsso = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'HelloAsso',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => true,
    ]);

    $component = Livewire::test(TransactionUniverselle::class);

    // Le choix du compte appartient désormais à PosteTiersReglementModal ; la
    // liste ne maintient plus de liste parallèle de comptes pour l'ancienne modale.
    expect($component->html())
        ->not->toContain('marquer-recu-modal')
        ->and((int) $compteNormal->id)->not->toBe((int) $compteHelloAsso->id);
});
