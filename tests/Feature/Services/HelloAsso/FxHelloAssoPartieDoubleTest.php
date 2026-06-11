<?php

declare(strict_types=1);

/**
 * FX-HelloAsso — Tests PD du flux sync HelloAsso.
 *
 * Vérifie que le TransactionConverter est appelé à la fin de synchroniser()
 * pour enrichir les transactions HelloAsso avec les écritures partie double.
 *
 * [A] Don CB → T1 (411D/7xxC) + T2 séparée (512X D / 411C), equilibree=true
 * [B] Cotisation chèque → T1 seul (411D/7xxC), statut EnAttente, pas de T2
 * [C] Re-sync idempotent → equilibree reste true, pas de doublons PD
 * [D] Don montant=0 (code promo) → skip PD, pas d'erreur
 */

use App\Enums\StatutReglement;
use App\Enums\TypeCategorie;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::clear();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($user);

    SystemeSeeder::seed();

    // Compte bancaire HelloAsso + Compte 512X associé
    $this->compteBancaireHA = CompteBancaire::factory()->create([
        'association_id' => (int) $this->asso->id,
        'nom' => 'HelloAsso',
    ]);
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '512_HA',
        'intitule' => 'Banque HelloAsso',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
        'compte_bancaire_id' => (int) $this->compteBancaireHA->id,
    ]);

    // Compte 7xx pour dons
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '754',
        'intitule' => 'Dons',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    // Compte 7xx pour cotisations
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '756',
        'intitule' => 'Cotisations',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $catRecette = Categorie::factory()->create([
        'association_id' => (int) $this->asso->id,
        'type' => TypeCategorie::Recette->value,
    ]);

    $this->scDon = SousCategorie::factory()->pourDons()->create([
        'association_id' => (int) $this->asso->id,
        'categorie_id' => (int) $catRecette->id,
        'nom' => 'Don',
        'code_cerfa' => '754',
    ]);

    $this->scCot = SousCategorie::factory()->pourCotisations()->create([
        'association_id' => (int) $this->asso->id,
        'categorie_id' => (int) $catRecette->id,
        'nom' => 'Cotisation',
        'code_cerfa' => '756',
    ]);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => (int) $this->asso->id,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => (int) $this->compteBancaireHA->id,
    ]);

    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => (int) $this->parametres->id,
        'form_slug' => 'dons-libres',
        'form_type' => 'Donation',
        'form_title' => 'Dons libres',
        'sous_categorie_id' => (int) $this->scDon->id,
    ]);
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => (int) $this->parametres->id,
        'form_slug' => 'adhesion-2025',
        'form_type' => 'Membership',
        'form_title' => 'Adhésion 2025',
        'sous_categorie_id' => (int) $this->scCot->id,
    ]);

    $this->tiers = Tiers::factory()->avecHelloasso()->create([
        'association_id' => (int) $this->asso->id,
        'email' => 'jean@test.com',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);

    $this->service = new HelloAssoSyncService($this->parametres);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * Helper : construit un order HelloAsso minimal.
 */
function makeHaOrder(
    int $orderId,
    string $formSlug,
    string $formType,
    string $itemType,
    int $amountCentimes,
    string $paymentMeans = 'Card',
): array {
    return [
        'id' => $orderId,
        'date' => '2025-10-15T10:00:00+02:00',
        'amount' => $amountCentimes,
        'formSlug' => $formSlug,
        'formType' => $formType,
        'items' => [
            [
                'id' => $orderId * 10 + 1,
                'amount' => $amountCentimes,
                'state' => 'Processed',
                'type' => $itemType,
                'name' => 'Test item',
            ],
        ],
        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
        'payments' => [
            [
                'id' => $orderId * 100 + 1,
                'amount' => $amountCentimes,
                'date' => '2025-10-15T10:00:00+02:00',
                'paymentMeans' => $paymentMeans,
                'cashOutState' => 'CashedOut',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// [A] Don CB → T1 + T2 séparée, equilibree=true
// ---------------------------------------------------------------------------

test('[A] don CB : T1 (411D/754C) + T2 (512X D/411C), equilibree=true', function (): void {
    $orders = [makeHaOrder(100, 'dons-libres', 'Donation', 'Donation', 5000)];

    $this->service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 100)->first();
    expect($tx)->not->toBeNull();
    expect($tx->equilibree)->toBeTrue('T1 doit être equilibree après enrichissement PD');

    // Ligne 754 C (produit)
    $compte754 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '754')->first();
    $ligne754 = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->where('compte_id', (int) $compte754->id)
        ->where('credit', '>', 0)->first();
    expect($ligne754)->not->toBeNull('Ligne 754 C attendue');
    expect((float) $ligne754->credit)->toBe(50.0);

    // Ligne 411 D (créance client)
    $compte411 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '411')->first();
    $ligne411D = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->where('compte_id', (int) $compte411->id)
        ->where('debit', '>', 0)->first();
    expect($ligne411D)->not->toBeNull('Ligne 411 D attendue');
    expect((float) $ligne411D->debit)->toBe(50.0);

    // T2 séparée (CB = comptant → encaissement immédiat)
    // La T2 porte 512X D / 411 C avec lettrage inter-tx
    expect($ligne411D->lettrage_code)->not->toBeNull('411 doit être lettré avec T2');

    $t2 = TransactionLigne::where('compte_id', (int) $compte411->id)
        ->where('lettrage_code', $ligne411D->lettrage_code)
        ->where('transaction_id', '!=', (int) $tx->id)
        ->first();
    expect($t2)->not->toBeNull('T2 doit exister avec même lettrage 411');

    // La T2 porte une ligne 512X au débit
    $compte512 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '512_HA')->first();
    $ligne512D = TransactionLigne::where('transaction_id', (int) $t2->transaction_id)
        ->where('compte_id', (int) $compte512->id)
        ->where('debit', '>', 0)->first();
    expect($ligne512D)->not->toBeNull('T2 doit avoir 512X D');
    expect((float) $ligne512D->debit)->toBe(50.0);
})->group('fx_helloasso');

// ---------------------------------------------------------------------------
// [B] Cotisation chèque → T1 seul (EnAttente), pas de T2
// ---------------------------------------------------------------------------

test('[B] cotisation chèque : T1 seul (411D/756C), EnAttente, pas de T2', function (): void {
    $orders = [makeHaOrder(200, 'adhesion-2025', 'Membership', 'Membership', 3000, 'Check')];

    $this->service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 200)->first();
    expect($tx)->not->toBeNull();
    expect($tx->equilibree)->toBeTrue();
    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);

    // Ligne 756 C (produit)
    $compte756 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '756')->first();
    $ligne756 = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->where('compte_id', (int) $compte756->id)
        ->where('credit', '>', 0)->first();
    expect($ligne756)->not->toBeNull('Ligne 756 C attendue');

    // 411 D sans lettrage (créance ouverte)
    $compte411 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '411')->first();
    $ligne411D = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->where('compte_id', (int) $compte411->id)
        ->where('debit', '>', 0)->first();
    expect($ligne411D)->not->toBeNull();
    expect($ligne411D->lettrage_code)->toBeNull('Créance ouverte → pas de lettrage');

    // Pas de T2
    $nbTx = Transaction::count();
    expect($nbTx)->toBe(1, 'Chèque EnAttente → T1 seule, pas de T2');
})->group('fx_helloasso');

// ---------------------------------------------------------------------------
// [C] Re-sync idempotent → pas de doublons PD
// ---------------------------------------------------------------------------

test('[C] re-sync idempotent : equilibree reste, pas de doublons PD', function (): void {
    $orders = [makeHaOrder(300, 'dons-libres', 'Donation', 'Donation', 2500)];

    // 1re sync
    $this->service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 300)->first();
    $nbLignesAvant = TransactionLigne::where('transaction_id', (int) $tx->id)->count();
    $nbTxAvant = Transaction::count();

    // 2e sync (re-sync)
    $this->service->synchroniser($orders, 2025);

    $tx->refresh();
    expect($tx->equilibree)->toBeTrue();

    $nbLignesApres = TransactionLigne::where('transaction_id', (int) $tx->id)->count();
    $nbTxApres = Transaction::count();

    expect($nbLignesApres)->toBe($nbLignesAvant, 'Re-sync ne doit pas dupliquer les lignes PD');
    expect($nbTxApres)->toBe($nbTxAvant, 'Re-sync ne doit pas créer de T2 supplémentaire');
})->group('fx_helloasso');

// ---------------------------------------------------------------------------
// [D] Don montant=0 (code promo) → skip PD, pas d'erreur
// ---------------------------------------------------------------------------

test('[D] don montant=0 : tx créée mais PD skippée (pas d\'erreur)', function (): void {
    $orders = [makeHaOrder(400, 'dons-libres', 'Donation', 'Donation', 0)];

    $this->service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 400)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(0.0);

    // Pas de lignes PD (converter skip montant_total=0)
    $lignesPd = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->whereNotNull('compte_id')
        ->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))
        ->count();
    expect($lignesPd)->toBe(0, 'Montant 0 → pas de PD');
})->group('fx_helloasso');
