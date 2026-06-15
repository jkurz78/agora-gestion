<?php

declare(strict_types=1);

/**
 * FX-Cotisation — Tests PD du wizard adhésion.
 *
 * [A] Adhésion payée via wizard → transaction avec écritures PD (411 D / 7xx C)
 * [B] Adhésion payée via wizard → statut dérivé correct (Recu pour virement comptant)
 * [C] Adhésion payée via wizard → T2 encaissement créée (portage D / 411 C)
 * [D] Wizard refuse la création si exercice cloturé
 * [E] Adhésion gratuite → pas de transaction, pas d'erreur PD
 */

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['compta.use_partie_double' => true]);

    $this->asso = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    // Compte bancaire + Compte 512X lié via compte_bancaire_id
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) $this->asso->id,
    ]);
    Compte::forceCreate([
        'association_id' => (int) $this->asso->id,
        'numero_pcg' => '512_MAIN',
        'intitule' => 'Banque principale',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
        'compte_bancaire_id' => (int) $this->compteBancaire->id,
    ]);

    // Sous-catégorie cotisation avec code_cerfa → compte 756
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

    $this->sousCat = SousCategorie::factory()->pourCotisations()->create([
        'association_id' => (int) $this->asso->id,
        'code_cerfa' => '756',
    ]);

    $this->formule = FormuleAdhesion::factory()->create([
        'association_id' => (int) $this->asso->id,
        'sous_categorie_id' => (int) $this->sousCat->id,
        'mode' => 'exercice',
    ]);

    $this->tiers = Tiers::factory()->create(['association_id' => (int) $this->asso->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// [A] Adhésion payée → écritures PD sur la transaction
// ---------------------------------------------------------------------------

test('[A] wizard adhésion payée : transaction créée avec lignes PD (411 D / 756 C)', function (): void {
    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30.0,
        notes: null,
        datePaiement: '2025-10-15',
        modePaiement: ModePaiement::Virement,
        compteId: (int) $this->compteBancaire->id,
        reference: 'COT-001',
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    // L'adhésion est créée
    expect($adhesion)->toBeInstanceOf(Adhesion::class);
    expect($adhesion->transaction_id)->not->toBeNull();

    // La transaction a des lignes PD
    $tx = Transaction::findOrFail($adhesion->transaction_id);
    $lignesPd = TransactionLigne::where('transaction_id', (int) $tx->id)
        ->whereNotNull('compte_id')
        ->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))
        ->get();

    // Au minimum : 1 ligne 756 C (ventilation enrichie) + 1 ligne 411 D (PD-only)
    expect($lignesPd->count())->toBeGreaterThanOrEqual(2);

    // Vérifier 411 D
    $compte411 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '411')
        ->first();
    $ligne411 = $lignesPd->firstWhere('compte_id', (int) $compte411->id);
    expect($ligne411)->not->toBeNull();
    expect((float) $ligne411->debit)->toBe(30.0);

    // Vérifier 756 C
    $compte756 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '756')
        ->first();
    $ligne756 = $lignesPd->firstWhere('compte_id', (int) $compte756->id);
    expect($ligne756)->not->toBeNull();
    expect((float) $ligne756->credit)->toBe(30.0);
})->group('fx_cotisation');

// ---------------------------------------------------------------------------
// [B] Adhésion payée → statut dérivé correct
// ---------------------------------------------------------------------------

test('[B] wizard adhésion payée virement : statut dérivé = Recu (remis)', function (): void {
    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 50.0,
        notes: null,
        datePaiement: '2025-10-15',
        modePaiement: ModePaiement::Virement,
        compteId: (int) $this->compteBancaire->id,
        reference: null,
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    $tx = Transaction::findOrFail($adhesion->transaction_id);
    expect($tx->statut_reglement)->toBe(StatutReglement::Recu);
})->group('fx_cotisation');

// ---------------------------------------------------------------------------
// [C] Adhésion payée → T2 encaissement créée
// ---------------------------------------------------------------------------

test('[C] wizard adhésion payée virement : T2 encaissement séparée créée (portage D / 411 C)', function (): void {
    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 40.0,
        notes: null,
        datePaiement: '2025-10-15',
        modePaiement: ModePaiement::Virement,
        compteId: (int) $this->compteBancaire->id,
        reference: null,
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    $txT1 = Transaction::findOrFail($adhesion->transaction_id);

    // Chercher la T2 via lettrage 411
    $compte411 = Compte::where('association_id', (int) $this->asso->id)
        ->where('numero_pcg', '411')
        ->first();

    $lettrageCode = TransactionLigne::where('transaction_id', (int) $txT1->id)
        ->where('compte_id', (int) $compte411->id)
        ->whereNotNull('lettrage_code')
        ->value('lettrage_code');

    expect($lettrageCode)->not->toBeNull();

    // La T2 a une ligne 411 C avec le même lettrage_code
    $ligneT2 = TransactionLigne::where('compte_id', (int) $compte411->id)
        ->where('lettrage_code', $lettrageCode)
        ->where('transaction_id', '!=', (int) $txT1->id)
        ->first();

    expect($ligneT2)->not->toBeNull();
    expect((float) $ligneT2->credit)->toBe(40.0);
})->group('fx_cotisation');

// ---------------------------------------------------------------------------
// [D] Exercice cloturé → refus
// ---------------------------------------------------------------------------

test('[D] wizard adhésion refuse si exercice cloturé', function (): void {
    // Cloturer l'exercice 2025
    $exerciceService = app(ExerciceService::class);
    $exercice = Exercice::create([
        'association_id' => (int) $this->asso->id,
        'annee' => 2025,
        'statut' => 'cloture',
        'cloture_par' => (int) $this->user->id,
        'cloture_at' => now(),
    ]);

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30.0,
        notes: null,
        datePaiement: '2025-10-15',
        modePaiement: ModePaiement::Virement,
        compteId: (int) $this->compteBancaire->id,
        reference: null,
    );

    expect(fn () => app(AdhesionService::class)->creerDepuisWizard($dto, $this->user))
        ->toThrow(ExerciceCloturedException::class);

    expect(Adhesion::count())->toBe(0);
    expect(Transaction::count())->toBe(0);
})->group('fx_cotisation');

// ---------------------------------------------------------------------------
// [E] Adhésion gratuite → pas de transaction, pas d'erreur
// ---------------------------------------------------------------------------

test('[E] wizard adhésion gratuite : pas de transaction, pas d\'erreur PD', function (): void {
    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 0.0,
        notes: "Membre d'honneur",
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    expect($adhesion->transaction_id)->toBeNull();
    expect(Transaction::count())->toBe(0);
})->group('fx_cotisation');

// ---------------------------------------------------------------------------
// [F] Pas de double adhésion (observer supprimé pendant le wizard)
// ---------------------------------------------------------------------------

test('[F] wizard adhésion payée : une seule adhésion créée (pas de doublon via observer)', function (): void {
    expect(Adhesion::count())->toBe(0, 'Pré-condition : 0 adhésion');

    $dto = new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formule->id,
        exercice: 2025,
        dateDebut: null,
        montant: 30.0,
        notes: null,
        datePaiement: '2025-10-15',
        modePaiement: ModePaiement::Virement,
        compteId: (int) $this->compteBancaire->id,
        reference: null,
    );

    $adhesion = app(AdhesionService::class)->creerDepuisWizard($dto, $this->user);

    // L'adhésion est créée par le wizard, pas par l'observer
    expect(Adhesion::count())->toBe(1);
    expect((int) $adhesion->tiers_id)->toBe((int) $this->tiers->id);
})->group('fx_cotisation');
