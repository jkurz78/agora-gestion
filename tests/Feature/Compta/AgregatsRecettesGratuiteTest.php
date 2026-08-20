<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\OperationDetail;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Tiers\TiersOperationsTimelineService;
use App\Services\TiersQuickViewService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Tâche 13 — agrégats de lecture au net des remises (chantier 709A)
|--------------------------------------------------------------------------
|
| Depuis la synchro HelloAsso à deux lignes (706A/751 au crédit, 709A au
| débit — toutes deux de classe 7), la colonne `montant` cesse d'être
| sommable naïvement : une inscription à 50 € entièrement offerte pose deux
| lignes à montant = 50,00 chacune. Un agrégat qui somme `montant` affiche
| 100 € au lieu de 0. Ce fichier fixe le scénario canonique (706A crédit 50 /
| 709A débit 50, une transaction, un tiers, une opération) et vérifie que les
| agrégats de lecture affichent le net.
|
| Le test « dépenses inchangées » est le garde-fou contre l'application
| aveugle de la règle : une gratuité n'existe que côté recette, jamais côté
| dépense — Σcredit − Σdebit rendrait une dépense négative si on la lui
| appliquait par erreur.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Carbon::setTestNow('2026-01-15') (bootstrap global tests/Pest.php) tombe
    // dans l'exercice sept. 2025 → août 2026, soit l'exercice 2025.
    $this->exercice = 2025;

    $this->tiers = Tiers::factory()->create();
    $this->operation = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
    ]);

    $compteBancaire = CompteBancaire::factory()->create();

    $compteProduit = Compte::factory()->numero('706A')->create();

    // Le compte 709A peut déjà exister pour ce tenant (migration de
    // rattrapage 2026_08_20_100000, rejouable) : firstOrCreate, jamais
    // create() — un create() nu casse sur l'unicité (association_id,
    // numero_pcg) si le 709A est déjà là.
    $compteGratuite = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '709A'],
        [
            'intitule' => 'Gratuités accordées',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
        ]
    );

    // Transaction remisée intégralement : 706A crédit 50 / 709A débit 50,
    // les deux lignes se compensent (montant_total = 0), rattachées au
    // tiers et à l'opération.
    $this->txRecette = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $compteBancaire->id,
        'date' => now()->toDateString(),
        'libelle' => 'Inscription offerte par code promo',
        'montant_total' => 0.00,
        'mode_paiement' => 'virement',
        'statut_reglement' => StatutReglement::Recu,
    ]);

    TransactionLigne::create([
        'transaction_id' => $this->txRecette->id,
        'compte_id' => $compteProduit->id,
        'operation_id' => $this->operation->id,
        'montant' => 50.00,
        'debit' => 0.00,
        'credit' => 50.00,
    ]);

    TransactionLigne::create([
        'transaction_id' => $this->txRecette->id,
        'compte_id' => $compteGratuite->id,
        'operation_id' => $this->operation->id,
        'montant' => 50.00,
        'debit' => 50.00,
        'credit' => 0.00,
    ]);

    // Dépense de contrôle, rattachée à la même opération : ne doit JAMAIS
    // être affectée par le passage au net — une gratuité n'apparaît que côté
    // recette, les agrégats de dépenses ne la voient jamais.
    $compteCharge = Compte::factory()->depense()->create();
    $tiersFournisseur = Tiers::factory()->pourDepenses()->create();
    $compteBancaireDepense = CompteBancaire::factory()->create();

    $this->txDepense = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Depense,
        'tiers_id' => $tiersFournisseur->id,
        'compte_id' => $compteBancaireDepense->id,
        'date' => now()->toDateString(),
        'libelle' => 'Achat matériel',
        'montant_total' => 80.00,
        'mode_paiement' => 'virement',
        'statut_reglement' => StatutReglement::Recu,
    ]);

    TransactionLigne::create([
        'transaction_id' => $this->txDepense->id,
        'compte_id' => $compteCharge->id,
        'operation_id' => $this->operation->id,
        'montant' => 80.00,
        'debit' => 80.00,
        'credit' => 0.00,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('1. l’opération affiche un produit net de 0, pas 100', function (): void {
    Livewire::test(OperationDetail::class, ['operation' => $this->operation])
        ->assertViewHas('totalRecettes', 0.0);
});

it('2. la fiche tiers affiche un total de recettes net, pas 100', function (): void {
    $summary = app(TiersQuickViewService::class)->getSummary($this->tiers, $this->exercice);

    expect($summary['recettes']['count'])->toBe(1)
        ->and((float) $summary['recettes']['total'])->toBe(0.0);
});

it('3. la timeline des opérations du tiers affiche le net, pas 100', function (): void {
    $dto = app(TiersOperationsTimelineService::class)->forTiers($this->tiers);

    expect($dto->lignes)->toHaveCount(1)
        ->and($dto->lignes[0]->montantPaye())->toBe(0.0);
});

it('4. compta:check-integrity ne signale aucune divergence sur une gratuité intégrale', function (): void {
    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});

it('5. les agrégats de dépenses restent inchangés : 80 € reste 80, jamais -80', function (): void {
    Livewire::test(OperationDetail::class, ['operation' => $this->operation])
        ->assertViewHas('totalDepenses', 80.0);
});
