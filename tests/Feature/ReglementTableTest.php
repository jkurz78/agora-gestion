<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\ReglementTable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

afterEach(function () {
    TenantContext::clear();
});

it('can create a reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement)->not->toBeNull();
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);
    expect((float) $reglement->montant_prevu)->toBe(30.00);
});

it('enforces unique participant-seance constraint', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 50.00,
    ]);
})->throws(QueryException::class);

it('cascades delete when seance is deleted', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    $seance->delete();
    expect(Reglement::count())->toBe(0);
});

it('has participant and seance relationships', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement->participant->id)->toBe($participant->id);
    expect($reglement->seance->id)->toBe($seance->id);
    expect($participant->reglements)->toHaveCount(1);
    expect($seance->reglements)->toHaveCount(1);
});

it('provides trigramme and reglement cases', function () {
    expect(ModePaiement::Cheque->trigramme())->toBe('CHQ');
    expect(ModePaiement::Virement->trigramme())->toBe('VMT');
    expect(ModePaiement::Especes->trigramme())->toBe('ESP');

    $cases = ModePaiement::reglementCases();
    expect($cases)->toHaveCount(3);
    expect($cases)->toContain(ModePaiement::Cheque);
    expect($cases)->not->toContain(ModePaiement::Cb);
});

it('cycles through reglement payment modes', function () {
    expect(ModePaiement::nextReglementMode(null))->toBe(ModePaiement::Cheque);
    expect(ModePaiement::nextReglementMode(ModePaiement::Cheque))->toBe(ModePaiement::Virement);
    expect(ModePaiement::nextReglementMode(ModePaiement::Virement))->toBe(ModePaiement::Especes);
    expect(ModePaiement::nextReglementMode(ModePaiement::Especes))->toBeNull();
});

it('renders reglement table', function () {
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->assertOk();
});

it('ouvre la modale de règlement daté sans créer de T2', function (): void {
    SystemeSeeder::seed();
    $produit = Compte::create([
        'numero_pcg' => '706-REGLEMENT-TABLE',
        'intitule' => 'Produit règlement table',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(),
        ventilations: [['compte' => $produit, 'montant' => 30.00]],
        dateConstatation: new DateTimeImmutable('2025-12-01'),
        libelle: 'Créance depuis règlements',
    );
    $ligne411 = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('marquerRecu', (int) $t1->id)
        ->assertDispatched('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne411?->id, exercice: 2025);

    expect(Transaction::count())->toBe(1)
        ->and($ligne411?->fresh()->lettrage_code)->toBeNull();
});

it('can cycle mode paiement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    // null → CHQ
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    $reglement = Reglement::first();
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);

    // CHQ → VMT
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    expect($reglement->fresh()->mode_paiement)->toBe(ModePaiement::Virement);
});

it('can update montant', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', $participant->id, $seance->id, '30,50');

    $reglement = Reglement::first();
    expect((float) $reglement->montant_prevu)->toBe(30.50);
});

it('can copy line from first seance', function () {
    $s1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $s2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2]);
    $s3 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 3]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s1->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 25.00,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('copierLigne', $participant->id);

    expect(Reglement::where('seance_id', $s2->id)->first()->montant_prevu)->toBe('25.00');
    expect(Reglement::where('seance_id', $s2->id)->first()->mode_paiement)->toBe(ModePaiement::Especes);
    expect(Reglement::where('seance_id', $s3->id)->first()->montant_prevu)->toBe('25.00');
});

it('refuses modification on locked reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => CompteBancaire::factory()->create()->id,
        'libelle' => 'Test remise',
        'saisi_par' => $this->user->id,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
        'remise_id' => $remise->id,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', $participant->id, $seance->id, '50,00');

    // Should not have changed
    expect((float) Reglement::first()->montant_prevu)->toBe(30.00);
});

it('copier ligne skips locked cells', function () {
    $s1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $s2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => CompteBancaire::factory()->create()->id,
        'libelle' => 'Test remise',
        'saisi_par' => $this->user->id,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s1->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 25.00,
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s2->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 10.00,
        'remise_id' => $remise->id,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('copierLigne', $participant->id);

    $s2Reg = Reglement::where('seance_id', $s2->id)->first();
    expect($s2Reg->mode_paiement)->toBe(ModePaiement::Especes);
    expect((float) $s2Reg->montant_prevu)->toBe(10.00);
});

it('refuses cycle on locked reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => now()->toDateString(),
        'mode_paiement' => 'cheque',
        'compte_cible_id' => CompteBancaire::factory()->create()->id,
        'libelle' => 'Test remise',
        'saisi_par' => $this->user->id,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
        'remise_id' => $remise->id,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    expect(Reglement::first()->mode_paiement)->toBe(ModePaiement::Cheque);
});

it('displays realized amounts from transactions', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 50.00,
    ]);

    // Create a recette transaction with matching tiers, operation, seance numero
    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement test',
        'montant_total' => 30.00,
        'mode_paiement' => 'cheque',
        'tiers_id' => $tiers->id,
        'compte_id' => CompteBancaire::first()?->id ?? CompteBancaire::factory()->create()->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'montant' => 30.00,
        'operation_id' => $this->operation->id,
        'seance' => 1,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->assertSee('30,00');
});

it('comptabilise une séance à la date saisie (défaut = date de la séance)', function () {
    // DC-10a : ventilation compte-first — comptes système requis pour la PD (411).
    SystemeSeeder::seed();
    $compteVentilation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706R',
        'intitule' => 'Recettes séances',
        'classe' => 7,
        'actif' => true,
    ]);
    $typeOp = TypeOperation::factory()->create(['compte_id' => $compteVentilation->id]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $compte = CompteBancaire::factory()->create(['actif_recettes_depenses' => true]);

    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-11-03',
    ]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->call('ouvrirComptabiliser', $seance->id)
        ->assertSet('comptabiliserDate', '2025-11-03')
        ->set('comptabiliserCompteId', $compte->id)
        ->call('comptabiliserSeance');

    $tx = Transaction::where('compte_id', $compte->id)->sole();
    expect($tx->date->format('Y-m-d'))->toBe('2025-11-03');
});

it('le bouton Aujourd\'hui remplace la date par celle du jour et est utilisée à la comptabilisation', function () {
    // DC-10a : ventilation compte-first — comptes système requis pour la PD (411).
    SystemeSeeder::seed();
    $compteVentilation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706R',
        'intitule' => 'Recettes séances',
        'classe' => 7,
        'actif' => true,
    ]);
    $typeOp = TypeOperation::factory()->create(['compte_id' => $compteVentilation->id]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $compte = CompteBancaire::factory()->create(['actif_recettes_depenses' => true]);

    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-11-03',
    ]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 20.00,
    ]);

    $aujourdhui = now()->format('Y-m-d');

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->call('ouvrirComptabiliser', $seance->id)
        ->call('setComptabiliserDateAujourdhui')
        ->assertSet('comptabiliserDate', $aujourdhui)
        ->set('comptabiliserCompteId', $compte->id)
        ->call('comptabiliserSeance');

    $tx = Transaction::where('compte_id', $compte->id)->sole();
    expect($tx->date->format('Y-m-d'))->toBe($aujourdhui);
});
