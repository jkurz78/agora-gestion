<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Livewire\TransactionUniverselle;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('se rend sans erreur', function () {
    Livewire::test(TransactionUniverselle::class)
        ->assertStatus(200);
});

it('accepte les props verrouillées en mount', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('supprime une recette via deleteRow', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
    ]);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'recette', $recette->id);
    $this->assertSoftDeleted('transactions', ['id' => $recette->id]);
});

it('ne supprime pas une transaction pointée', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
        'statut_reglement' => 'pointe',
    ]);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'depense', $tx->id);
    $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'deleted_at' => null]);
});

it('la page /transactions rend TransactionUniverselle avec lockedTypes depense+recette', function () {
    $this->get('/comptabilite/transactions')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['depense', 'recette']])
        ->assertSet('lockedTypes', ['depense', 'recette']);
});

it('la page /comptes-bancaires/{id}/transactions rend TransactionUniverselle avec compteId', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->get("/banques/comptes/{$compte->id}/transactions")
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('la page /tiers/{id}/transactions rend TransactionUniverselle avec tiersId', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->get("/tiers/{$tiers->id}/transactions")
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);
});

it('la page /dons rend TransactionUniverselle avec usageFilter pour_dons', function () {
    $this->get('/comptabilite/dons')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['usageFilter' => 'pour_dons'])
        ->assertSet('usageFilter', 'pour_dons');
});

it('la page /cotisations rend TransactionUniverselle avec usageFilter pour_cotisations', function () {
    $this->get('/comptabilite/cotisations')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['usageFilter' => 'pour_cotisations'])
        ->assertSet('usageFilter', 'pour_cotisations');
});

it('affiche un report AN immuable et ouvre son règlement daté', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $produit = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706-REPORT-ECRAN',
        'intitule' => 'Produit report écran',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id]),
        ventilations: [['compte' => $produit, 'montant' => 42.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance report écran',
    );
    $ligneAN = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    )->origines()->with('ligneAN')->firstOrFail()->ligneAN;
    session(['exercice_actif' => 2026]);

    $component = Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['recette'], 'exercice' => 2026])
        ->assertSee('Report AN')
        ->call('marquerRecu', (int) $ligneAN->id, 'report_an', (int) $ligneAN->id)
        ->assertDispatched('poste-tiers-reglement:ouvrir', ligneId: (int) $ligneAN->id, exercice: 2026);

    preg_match(
        "/<tr[^>]*wire:click=\"toggleDetail\('report_an', {$ligneAN->id}\)\".*?<\\/tr>/s",
        $component->html(),
        $matches,
    );

    expect($matches)->toHaveCount(1)
        ->and($matches[0])->not->toContain("openEdit('report_an'")
        ->not->toContain("deleteRow('report_an'")
        ->not->toContain('extourne:open')
        ->not->toContain('Cette transaction ne peut être ni supprimée ni annulée')
        ->not->toContain('bi bi-trash');

    expect(Transaction::count())->toBe(2);
});

it('développe le détail exploitable d un report AN', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $produit = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706-REPORT-DETAIL',
        'intitule' => 'Produit report détail',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Client détail']),
        ventilations: [['compte' => $produit, 'montant' => 42.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance report détaillée',
    );
    $ligneAN = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    )->origines()->with('ligneAN')->firstOrFail()->ligneAN;
    session(['exercice_actif' => 2026]);

    $component = Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['recette'], 'exercice' => 2026])
        ->call('toggleDetail', 'report_an', (int) $ligneAN->id);

    $detail = $component->get('expandedDetails')["report_an:{$ligneAN->id}"];

    $component
        ->assertSeeHtml('<th>Compte</th>')
        ->assertSee($detail['lignes'][0]['compte'])
        ->assertSee($detail['lignes'][0]['operation'])
        ->assertSee($detail['lignes'][0]['notes']);

    expect($detail['lignes'])->not->toBeEmpty()
        ->and($detail['lignes'][0])->toHaveKeys(['compte', 'operation', 'montant', 'notes']);
});

it('affiche les reports AN de l exercice fixé même si la session vise un autre exercice', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $produit = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706-REPORT-EXERCICE-COMPOSANT',
        'intitule' => 'Produit report exercice composant',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id]),
        ventilations: [['compte' => $produit, 'montant' => 25.00]],
        dateConstatation: new DateTimeImmutable('2025-08-20'),
        libelle: 'Créance exercice fixé composant',
    );
    app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2024),
        OrigineANouveau::Cloture,
        $acteur,
    );
    session(['exercice_actif' => 2026]);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['recette'], 'exercice' => 2025])
        ->assertSee('Report AN')
        ->assertSee('Créance exercice fixé composant');
});
