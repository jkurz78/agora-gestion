<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Livewire\TransactionUniverselle;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// DC-10a : le filtre par usage lit usages_comptes.compte_id — les
// fixtures créent des comptes (classe 7) flaggés, plus de comptes.
function compteRecetteFiltreTest(string $numeroPcg, ?UsageComptable $usage = null): Compte
{
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numeroPcg,
        'intitule' => 'Compte test '.$numeroPcg,
        'classe' => 7,
        'actif' => true,
    ]);

    if ($usage !== null) {
        $compte->usages()->create(['usage' => $usage->value]);
    }

    return $compte;
}

it('filters transactions by compte pour_dons flag', function () {
    $compte = CompteBancaire::factory()->create();
    $compteDon = compteRecetteFiltreTest('754T', UsageComptable::Don);
    $compteAutre = compteRecetteFiltreTest('706T');

    $today = now()->toDateString();

    $txDon = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Don transaction visible',
    ]);
    // Remove auto-created lines, then add specific ones
    TransactionLigne::where('transaction_id', $txDon->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txDon->id,
        'compte_id' => $compteDon->id,
        'montant' => 100,
        'debit' => 0,
        'credit' => 100,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Autre transaction cachée',
    ]);
    TransactionLigne::where('transaction_id', $txAutre->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'compte_id' => $compteAutre->id,
        'montant' => 50,
        'debit' => 0,
        'credit' => 50,
    ]);

    Livewire::test(TransactionUniverselle::class, [
        'usageFilter' => 'pour_dons',
    ])
        ->assertSee('Don transaction visible')
        ->assertDontSee('Autre transaction cachée');
});

it('filters transactions by compte pour_cotisations flag', function () {
    $compte = CompteBancaire::factory()->create();
    $compteCot = compteRecetteFiltreTest('756T', UsageComptable::Cotisation);
    $compteAutre = compteRecetteFiltreTest('706U');

    $today = now()->toDateString();

    $txCot = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Cotisation transaction visible',
    ]);
    TransactionLigne::where('transaction_id', $txCot->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txCot->id,
        'compte_id' => $compteCot->id,
        'montant' => 80,
        'debit' => 0,
        'credit' => 80,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Autre cotisation cachée',
    ]);
    TransactionLigne::where('transaction_id', $txAutre->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'compte_id' => $compteAutre->id,
        'montant' => 30,
        'debit' => 0,
        'credit' => 30,
    ]);

    Livewire::test(TransactionUniverselle::class, [
        'usageFilter' => 'pour_cotisations',
    ])
        ->assertSee('Cotisation transaction visible')
        ->assertDontSee('Autre cotisation cachée');
});
