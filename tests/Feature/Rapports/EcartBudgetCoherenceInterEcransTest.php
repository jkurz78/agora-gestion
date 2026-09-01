<?php

declare(strict_types=1);

// Verrou de non-régression : la convention d'écart budgétaire a été unifiée
// (2026-09-01) entre les trois écrans (compte de résultat, écran Budget,
// tuile dashboard) autour de « écart = réalisé - prévu », brut, la couleur
// seule portant l'appréciation. Ce test empêche l'écran Budget de reprendre
// sa propre convention (favorable = positif) et de re-diverger du compte de
// résultat sur un compte de CHARGE — le seul cas où les deux anciennes
// conventions n'étaient pas déjà d'accord.

use App\Livewire\BudgetTable;
use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Support\ComparaisonBudgetaire;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

it('un compte de charge en depassement affiche le meme ecart signe sur l ecran Budget et le compte de resultat', function () {
    $compte = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'montant_prevu' => 1000.00,
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 1300.00,
        'credit' => 0,
        'montant' => 1300.00,
    ]);

    // Fait brut, indépendant de la nature de la ligne : 1300 réalisé - 1000
    // prévu = +300, en dépassement.
    expect(ComparaisonBudgetaire::ecart(1000.00, 1300.00))->toBe(300.0);

    $htmlBudget = Livewire::test(BudgetTable::class)->assertOk()->html();
    $htmlCompteResultat = Livewire::test(RapportCompteResultat::class)->assertOk()->html();

    // Même valeur, même signe (+300,00) sur les deux écrans, et rouge sur les
    // deux : une dépense qui dépasse son budget est une mauvaise nouvelle,
    // qu'on la lise sur l'écran Budget (text-danger) ou le compte de
    // résultat (cr-neg). Pas de contrôle « -300,00 absent » sur l'écran
    // Budget : avec un seul compte de charge et aucun produit, la ligne
    // Résultat affiche légitimement son propre écart à -300,00 (le résultat
    // réalisé est PIRE que prévu) — ce n'est pas ce que ce test vérifie.
    expect($htmlBudget)->toMatch('/text-danger">[\s\S]{0,80}?\+300,00/')
        ->and($htmlCompteResultat)->toMatch('/cr-neg">\+300,00/')
        ->and($htmlCompteResultat)->not->toMatch('/-300,00/');
});

it('un compte de charge sous-consomme affiche le meme ecart negatif favorable sur les deux ecrans', function () {
    $compte = Compte::factory()->numero('628')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Assurances',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'montant_prevu' => 1000.00,
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 700.00,
        'credit' => 0,
        'montant' => 700.00,
    ]);

    // 700 réalisé - 1000 prévu = -300, favorable pour une charge.
    expect(ComparaisonBudgetaire::ecart(1000.00, 700.00))->toBe(-300.0);

    $htmlBudget = Livewire::test(BudgetTable::class)->assertOk()->html();
    $htmlCompteResultat = Livewire::test(RapportCompteResultat::class)->assertOk()->html();

    // -300,00 sur les deux écrans, mais PAS en rouge sur l'écran Budget
    // (favorable) et en vert (cr-pos) sur le compte de résultat.
    expect($htmlBudget)->toContain('-300,00')
        ->and($htmlBudget)->not->toMatch('/text-danger">[\s\S]{0,80}?-300,00/')
        ->and($htmlCompteResultat)->toMatch('/cr-pos">-300,00/');
});
