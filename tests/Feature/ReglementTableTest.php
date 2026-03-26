<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\ReglementTable;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
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
