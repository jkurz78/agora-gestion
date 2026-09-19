<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Livewire\ReglementTable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
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
        'numero_pcg' => '706-REGTB',
        'intitule' => 'Produit règlement table',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $t1 = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(),
        ventilations: [['sens' => SensVentilation::Credit, 'compte' => $produit, 'montant' => 30.00]],
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

/** Lie une transaction au règlement, comme le fait la comptabilisation. */
function comptabiliserReglementTableTest(Reglement $reglement): Transaction
{
    return Transaction::create([
        'type' => 'recette',
        'date' => '2025-11-15',
        'libelle' => 'Règlement comptabilisé',
        'montant_total' => (float) $reglement->montant_prevu,
        'mode_paiement' => $reglement->mode_paiement?->value,
        'tiers_id' => (int) $reglement->participant->tiers_id,
        'compte_id' => (int) CompteBancaire::factory()->create()->id,
        'reglement_id' => (int) $reglement->id,
    ]);
}

it('refuse de modifier le montant d\'un règlement comptabilisé', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $reglement = reglementTableTest($this->operation, $seance, ModePaiement::Cheque, 30.0);
    comptabiliserReglementTableTest($reglement);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', (int) $reglement->participant_id, $seance->id, '50,00');

    expect((float) $reglement->fresh()->montant_prevu)->toBe(30.00);
});

it('la recopie de ligne saute les séances comptabilisées', function () {
    $s1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $s2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2]);
    $source = reglementTableTest($this->operation, $s1, ModePaiement::Cheque, 25.0);
    $cible = Reglement::create([
        'participant_id' => (int) $source->participant_id,
        'seance_id' => (int) $s2->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 10.00,
    ]);
    comptabiliserReglementTableTest($cible);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('copierLigne', (int) $source->participant_id);

    $cible->refresh();
    expect($cible->mode_paiement)->toBe(ModePaiement::Especes)
        ->and((float) $cible->montant_prevu)->toBe(10.00);
});

it('refuse de changer le mode d\'un règlement comptabilisé', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $reglement = reglementTableTest($this->operation, $seance, ModePaiement::Cheque, 30.0);
    comptabiliserReglementTableTest($reglement);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', (int) $reglement->participant_id, $seance->id);

    expect($reglement->fresh()->mode_paiement)->toBe(ModePaiement::Cheque);
});

it('déverrouille la case quand la transaction est supprimée', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $reglement = reglementTableTest($this->operation, $seance, ModePaiement::Cheque, 30.0);
    comptabiliserReglementTableTest($reglement)->delete();

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', (int) $reglement->participant_id, $seance->id, '50,00');

    expect((float) $reglement->fresh()->montant_prevu)->toBe(50.00);
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

/**
 * Opération dont le type porte un compte de produit (classe 7) — prérequis de
 * la comptabilisation — avec une séance datée du 2025-11-03.
 *
 * @return array{operation: Operation, seance: Seance, compte: CompteBancaire}
 */
function seanceComptabilisableTableTest(): array
{
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
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-11-03',
    ]);

    return [
        'operation' => $operation,
        'seance' => $seance,
        'compte' => CompteBancaire::factory()->create(['actif_recettes_depenses' => true]),
    ];
}

function reglementTableTest(Operation $operation, Seance $seance, ?ModePaiement $mode, float $montant = 30.0): Reglement
{
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    return Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $seance->id,
        'mode_paiement' => $mode?->value,
        'montant_prevu' => $montant,
    ]);
}

it('comptabilise les règlements prêts et laisse de côté ceux sans mode, sans erreur', function () {
    ['operation' => $operation, 'seance' => $seance, 'compte' => $compte] = seanceComptabilisableTableTest();
    $avecMode = reglementTableTest($operation, $seance, ModePaiement::Cheque);
    $sansMode = reglementTableTest($operation, $seance, null);

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->call('ouvrirComptabiliser', $seance->id)
        ->set('comptabiliserCompteId', $compte->id)
        ->call('comptabiliserSeance')
        ->assertHasNoErrors()
        ->assertSet('showComptabiliserModal', false)
        ->assertDispatched('comptabiliser-modal-close');

    expect($avecMode->fresh()->estComptabilise())->toBeTrue()
        ->and($sansMode->fresh()->estComptabilise())->toBeFalse();
});

it('affiche l\'état de chaque règlement dans sa case', function () {
    ['operation' => $operation, 'seance' => $seance] = seanceComptabilisableTableTest();
    reglementTableTest($operation, $seance, ModePaiement::Cheque);
    reglementTableTest($operation, $seance, ModePaiement::Especes);
    reglementTableTest($operation, $seance, null);

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->assertSee('À comptabiliser')
        ->assertSee('Sans mode')
        ->assertSee('Comptabiliser (2)')
        ->call('ouvrirComptabiliser', $seance->id)
        ->assertSee('Créer 2 transactions');
});

it('verrouille la case et retire « À comptabiliser » une fois le règlement comptabilisé', function () {
    ['operation' => $operation, 'seance' => $seance] = seanceComptabilisableTableTest();
    $reglement = reglementTableTest($operation, $seance, ModePaiement::Cheque);
    reglementTableTest($operation, $seance, null);
    comptabiliserReglementTableTest($reglement);

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->assertDontSee('À comptabiliser')
        ->assertSee('Déjà comptabilisé')
        ->assertSee('Sans mode')
        ->assertSee('Aucun règlement prêt');
});

it('affiche « Comptabilisé » après deux passages', function () {
    ['operation' => $operation, 'seance' => $seance, 'compte' => $compte] = seanceComptabilisableTableTest();
    reglementTableTest($operation, $seance, ModePaiement::Cheque);
    $tardif = reglementTableTest($operation, $seance, null);

    $composant = Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->call('ouvrirComptabiliser', $seance->id)
        ->set('comptabiliserCompteId', $compte->id)
        ->call('comptabiliserSeance')
        ->assertDontSeeHtml('&#10003; Comptabilisé');

    $tardif->update(['mode_paiement' => ModePaiement::Virement->value]);

    $composant->call('ouvrirComptabiliser', $seance->id)
        ->set('comptabiliserCompteId', $compte->id)
        ->call('comptabiliserSeance')
        ->assertSeeHtml('&#10003; Comptabilisé');

    expect(Transaction::whereNotNull('reglement_id')->count())->toBe(2);
});

it('montre le statut « Dû » à un lecteur sans droit d\'écriture', function () {
    ['operation' => $operation, 'seance' => $seance] = seanceComptabilisableTableTest();
    $reglement = reglementTableTest($operation, $seance, ModePaiement::Cheque);
    comptabiliserReglementTableTest($reglement);

    $lecteur = User::factory()->create();
    $lecteur->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
    $this->actingAs($lecteur);

    Livewire::test(ReglementTable::class, ['operation' => $operation])
        ->assertDontSee('Marquer reçu')
        ->assertSee('Dû');
});
