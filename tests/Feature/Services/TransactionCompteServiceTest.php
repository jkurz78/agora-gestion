<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\TransactionCompteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionCompteService::class);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'nom' => 'Compte Principal',
    ]);
});

it('retourne une recette avec montant positif', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $recette = $items->firstWhere('source_type', 'recette');
    expect($recette)->not->toBeNull();
    expect((float) $recette->montant)->toBe(200.00);
});

it('retourne une depense avec montant negatif', function () {
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 150.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $depense = $items->firstWhere('source_type', 'depense');
    expect($depense)->not->toBeNull();
    expect((float) $depense->montant)->toBe(-150.00);
});

it('retourne un don avec le nom du donateur comme tiers', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['prenom' => 'Marie', 'nom' => 'Dupont', 'type' => 'particulier']);
    Don::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers_id' => $tiers->id,
        'montant' => 50.00,
        'date' => '2025-10-03',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $don = $items->firstWhere('source_type', 'don');
    expect($don)->not->toBeNull();
    expect((float) $don->montant)->toBe(50.00);
    expect(trim($don->tiers))->toContain('Marie');
    expect(trim($don->tiers))->toContain('Dupont');
});

it('retourne une cotisation avec le nom du membre comme tiers', function () {
    $tiers = Tiers::factory()->membre()->create(['prenom' => 'Jean', 'nom' => 'Martin']);
    Cotisation::factory()->create([
        'compte_id' => $this->compte->id,
        'tiers_id' => $tiers->id,
        'montant' => 80.00,
        'date_paiement' => '2025-10-04',
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $cotisation = $items->firstWhere('source_type', 'cotisation');
    expect($cotisation)->not->toBeNull();
    expect((float) $cotisation->montant)->toBe(80.00);
    expect(trim($cotisation->tiers))->toContain('Jean');
    expect(trim($cotisation->tiers))->toContain('Martin');
});

it('un virement depuis compte A vers B apparaît sur A comme virement_sortant négatif, tiers = nom de B', function () {
    $compteB = CompteBancaire::factory()->create(['nom' => 'Compte Épargne']);
    VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compteB->id,
        'montant' => 300.00,
        'date' => '2025-10-05',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $virement = $items->firstWhere('source_type', 'virement_sortant');
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(-300.00);
    expect($virement->tiers)->toBe('Compte Épargne');
});

it('un virement depuis B vers compte A apparaît sur A comme virement_entrant positif, tiers = nom de B', function () {
    $compteB = CompteBancaire::factory()->create(['nom' => 'Compte Épargne']);
    VirementInterne::factory()->create([
        'compte_source_id' => $compteB->id,
        'compte_destination_id' => $this->compte->id,
        'montant' => 250.00,
        'date' => '2025-10-06',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    $items = collect($result['paginator']->items());
    $virement = $items->firstWhere('source_type', 'virement_entrant');
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(250.00);
    expect($virement->tiers)->toBe('Compte Épargne');
});

it('filtre par date : seules les transactions dans la plage apparaissent', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-09-15',
        'saisi_par' => $this->user->id,
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: '2025-10-01',
        dateFin: '2025-10-31',
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(0);
});

it('filtre par tiers : seules les transactions correspondantes apparaissent', function () {
    $tiersTartempion = Tiers::factory()->create([
        'type' => 'entreprise',
        'nom' => 'Association Tartempion',
        'prenom' => null,
        'pour_recettes' => true,
    ]);
    $tiersLyon = Tiers::factory()->create([
        'type' => 'entreprise',
        'nom' => 'Mairie de Lyon',
        'prenom' => null,
        'pour_recettes' => true,
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'tiers_id' => $tiersTartempion->id,
        'montant_total' => 100.00,
        'date' => '2025-10-10',
        'saisi_par' => $this->user->id,
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'tiers_id' => $tiersLyon->id,
        'montant_total' => 200.00,
        'date' => '2025-10-11',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: 'Tartempion',
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(1);
    $item = collect($result['paginator']->items())->first();
    expect($item->tiers)->toBe('Association Tartempion');
});

it('les recettes soft-deleted n\'apparaissent pas', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-10',
        'saisi_par' => $this->user->id,
    ]);
    $recette->delete();

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['paginator']->total())->toBe(0);
});

it('soldeAvantPage sur page 1 est égal à solde_initial', function () {
    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    expect($result['soldeAvantPage'])->toBe(1000.00);
    expect($result['showSolde'])->toBeTrue();
});

it('soldeAvantPage sur page 2 est égal à solde_initial + somme des 15 premières transactions', function () {
    // Créer 16 recettes pour avoir au moins 2 pages
    for ($i = 1; $i <= 16; $i++) {
        Transaction::factory()->asRecette()->create([
            'compte_id' => $this->compte->id,
            'montant_total' => 10.00,
            'date' => '2025-10-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'saisi_par' => $this->user->id,
        ]);
    }

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 2,
    );

    // solde_initial (1000) + 15 × 10.00 = 1150.00
    expect($result['soldeAvantPage'])->toBe(1150.00);
});

it('accumule le solde_courant ligne par ligne sur la page courante', function () {
    // 3 transactions : +100, -30, +50 → soldes cumulés attendus : 1100, 1070, 1120
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 30.00,
        'date' => '2025-10-02',
        'saisi_par' => $this->user->id,
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 50.00,
        'date' => '2025-10-03',
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->paginate(
        compte: $this->compte,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        sortColumn: 'date',
        sortDirection: 'asc',
        perPage: 15,
        page: 1,
    );

    // Simuler l'accumulation PHP comme le fait TransactionCompteList::render()
    $solde = $result['soldeAvantPage']; // 1000.00
    $attendus = [1100.00, 1070.00, 1120.00];
    foreach (collect($result['paginator']->items()) as $i => $tx) {
        $solde += (float) $tx->montant;
        expect(round($solde, 2))->toBe($attendus[$i]);
    }
});
