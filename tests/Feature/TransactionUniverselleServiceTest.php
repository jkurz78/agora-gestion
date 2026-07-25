<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->svc = app(TransactionUniverselleService::class);
    $this->compte = CompteBancaire::factory()->create(['solde_initial' => 0]);
});

it('retourne toutes les entités par défaut', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asRecette()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);
    VirementInterne::factory()->create(['compte_source_id' => $this->compte->id, 'date' => '2025-01-13']);

    $result = $this->svc->paginate(null, null, null, null, null, null, null, null, null, null, null);
    // depense + recette + virement_sortant + virement_entrant = 4
    expect($result['paginator']->total())->toBe(4);
});

it('filtre par compteId', function () {
    $autreCompte = CompteBancaire::factory()->create();
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asDepense()->create(['compte_id' => $autreCompte->id, 'date' => '2025-01-10']);

    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(1);
});

it('filtre sur types uniquement depense', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asRecette()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);

    $result = $this->svc->paginate(null, null, ['depense'], null, null, null, null, null, null, null, null);
    foreach ($result['paginator']->items() as $row) {
        expect($row->source_type)->toBe('depense');
    }
});

it('filtre par dateDebut et dateFin', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-05']);
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-03-15']);

    $result = $this->svc->paginate(null, null, ['depense'], '2025-03-01', '2025-03-31', null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(1);
    expect($result['paginator']->items()[0]->date)->toBe('2025-03-15');
});

it('retourne soldeAvantPage non-null quand computeSolde=true et compteId fourni', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'montant_total' => 100.0,
        'mode_paiement' => 'virement',
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-11',
        'montant_total' => 100.0,
        'mode_paiement' => 'virement',
    ]);

    // Page 2 with perPage=1: soldeAvantPage = solde_initial (0) + 100 (first page row)
    $result = $this->svc->paginate(
        $this->compte->id, null, ['recette'], null, null, null, null, null, null, null, null,
        null, true, 'date', 'asc', 1, 2
    );
    expect($result['soldeAvantPage'])->toBe(100.0);
});

it('retourne soldeAvantPage null quand computeSolde=false', function () {
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, null, false);
    expect($result['soldeAvantPage'])->toBeNull();
});

it('exclut les virements quand tiersId est fourni', function () {
    $compteSource = CompteBancaire::factory()->create();
    VirementInterne::factory()->create([
        'compte_source_id' => $compteSource->id,
        'compte_destination_id' => $this->compte->id,
        'date' => '2025-06-01',
    ]);
    $tiers = Tiers::factory()->create();

    $result = $this->svc->paginate(null, $tiers->id, ['virement'], null, null, null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(0);
});

it('affiche la raison sociale d\'un tiers entreprise sans nom ni prénom', function () {
    $entreprise = Tiers::factory()->entreprise()->create([
        'entreprise' => 'EPSILON MELIA',
        'nom' => null,
        'prenom' => null,
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'tiers_id' => $entreprise->id,
    ]);

    $result = $this->svc->paginate(null, $entreprise->id, ['recette'], null, null, null, null, null, null, null, null);

    expect($result['paginator']->total())->toBe(1);
    expect($result['paginator']->items()[0]->tiers)->toBe('EPSILON MELIA');
});

it('combine raison sociale et contact pour une entreprise avec personne de contact', function () {
    $entreprise = Tiers::factory()->entreprise()->create([
        'entreprise' => 'EPONA',
        'nom' => 'Kerecki',
        'prenom' => 'Agnes',
    ]);

    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'tiers_id' => $entreprise->id,
    ]);

    $result = $this->svc->paginate(null, $entreprise->id, ['depense'], null, null, null, null, null, null, null, null);

    expect($result['paginator']->items()[0]->tiers)->toBe('EPONA (Agnes KERECKI)');
});

it('affiche le contact prénom+nom pour un tiers particulier', function () {
    $particulier = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Durand',
        'prenom' => 'Marie',
        'entreprise' => null,
    ]);

    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'tiers_id' => $particulier->id,
    ]);

    $result = $this->svc->paginate(null, $particulier->id, ['depense'], null, null, null, null, null, null, null, null);

    expect($result['paginator']->items()[0]->tiers)->toBe('Marie DURAND');
});

it('filtre par modePaiement', function () {
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'mode_paiement' => 'cheque',
    ]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-11',
        'mode_paiement' => 'virement',
    ]);

    $result = $this->svc->paginate(null, null, ['depense'], null, null, null, null, null, null, 'cheque', null);
    expect($result['paginator']->total())->toBe(1);
});

it('expose une seule ligne report AN avec les informations de la transaction d origine', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach((int) TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);

    $tiers = Tiers::factory()->create(['nom' => 'Client report']);
    $produit = Compte::create([
        'numero_pcg' => '706-REPORT',
        'intitule' => 'Produit reporté',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $produit, 'montant' => 42.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance à reporter',
    );
    $t1->update(['reference' => 'REF-CLIENT-42']);
    $ligneAN = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    )->origines()->with('ligneAN.compte')->firstOrFail()->ligneAN;

    session(['exercice_actif' => 2026]);
    $result = $this->svc->paginate(
        null,
        null,
        ['recette'],
        '2026-09-01',
        '2027-08-31',
        null,
        null,
        null,
        null,
        null,
        null,
    );

    $row = collect($result['paginator']->items())->firstWhere('source_type', 'report_an');

    expect($row)->not->toBeNull()
        ->and($row->date)->toBe('2026-09-01')
        ->and($row->numero_piece)->toBe($t1->numero_piece)
        ->and($row->reference)->toBe('REF-CLIENT-42')
        ->and((int) $row->poste_tiers_ligne_id)->toBe((int) $ligneAN->id)
        ->and($row->sens_tresorerie)->toBe('recette');

    $resultN = $this->svc->paginate(
        null,
        null,
        ['recette'],
        '2025-09-01',
        '2026-08-31',
        null,
        null,
        null,
        null,
        null,
        null,
    );

    expect(collect($resultN['paginator']->items())->where('source_type', 'report_an'))->toBeEmpty();
});

it('utilise l exercice demandé pour les reports AN plutôt que celui de la session', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach((int) TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $tiers = Tiers::factory()->create(['nom' => 'Client exercice affiché']);
    $produit = Compte::create([
        'numero_pcg' => '706-REPORT-EXERCICE',
        'intitule' => 'Produit report exercice',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $transaction = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $produit, 'montant' => 25.00]],
        dateConstatation: new DateTimeImmutable('2025-08-20'),
        libelle: 'Créance exercice affiché',
    );
    $ligneAN = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2024),
        OrigineANouveau::Cloture,
        $acteur,
    )->origines()->with('ligneAN')->firstOrFail()->ligneAN;

    session(['exercice_actif' => 2026]);
    $result = $this->svc->paginate(
        null, null, ['recette'], '2025-09-01', '2026-08-31', null, null, null, null, null, null,
        null, false, 'date', 'desc', 25, 1, false, 2025,
    );

    $report = collect($result['paginator']->items())->firstWhere('source_type', 'report_an');

    expect($report)->not->toBeNull()
        ->and($report->numero_piece)->toBe($transaction->numero_piece)
        ->and((int) $report->poste_tiers_ligne_id)->toBe((int) $ligneAN->id);
});

it('filtre les reports AN par bornes, tiers, référence, pièce et sens', function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $acteur = User::factory()->create();
    $acteur->associations()->attach((int) TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $client = Tiers::factory()->create(['nom' => 'Client AN']);
    $fournisseur = Tiers::factory()->create(['nom' => 'Fournisseur AN']);
    $produit = Compte::create([
        'numero_pcg' => '706-REPORT-FILTRE',
        'intitule' => 'Produit report filtre',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $charge = Compte::create([
        'numero_pcg' => '606-REPORT-FILTRE',
        'intitule' => 'Charge report filtre',
        'classe' => 6,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $client,
        ventilations: [['compte' => $produit, 'montant' => 20.00]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance filtrée',
    );
    $dette = app(EcritureGenerator::class)->pourDepenseACredit(
        tiers: $fournisseur,
        ventilations: [['compte' => $charge, 'montant' => 30.00]],
        dateConstatation: new DateTimeImmutable('2026-08-21'),
        libelle: 'Dette filtrée',
    );
    $dette->update(['reference' => 'REF-FOURN-30']);
    app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $acteur,
    );
    session(['exercice_actif' => 2026]);

    $result = $this->svc->paginate(
        null,
        (int) $fournisseur->id,
        ['depense'],
        '2026-09-01',
        '2026-09-01',
        null,
        null,
        'REF-FOURN-30',
        (string) $dette->numero_piece,
        null,
        null,
    );

    $rows = collect($result['paginator']->items());
    expect($rows)->toHaveCount(1)
        ->and($rows->sole()->source_type)->toBe('report_an')
        ->and($rows->sole()->sens_tresorerie)->toBe('depense')
        ->and((float) $rows->sole()->montant)->toBe(-30.0);
});
