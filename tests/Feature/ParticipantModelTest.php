<?php

declare(strict_types=1);

use App\Enums\DroitImage;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

test('participant belongs to tiers and operation', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    expect($participant->tiers->id)->toBe($tiers->id);
    expect($participant->operation->id)->toBe($operation->id);
});

test('participant unique constraint on tiers and operation', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
})->throws(QueryException::class);

test('tiers can participate in multiple operations', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $op1 = Operation::factory()->create(['association_id' => $this->association->id]);
    $op2 = Operation::factory()->create(['association_id' => $this->association->id]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id, 'date_inscription' => now()]);
    expect($tiers->participants)->toHaveCount(2);
});

test('operation has many participants', function (): void {
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $t1 = Tiers::factory()->create(['association_id' => $this->association->id]);
    $t2 = Tiers::factory()->create(['association_id' => $this->association->id]);
    Participant::create(['tiers_id' => $t1->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $t2->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    expect($operation->participants)->toHaveCount(2);
});

test('donnees medicales are encrypted and linked to participant', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => Operation::factory()->create(['association_id' => $this->association->id])->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $donnees = ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1985-06-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);
    $donnees->refresh();
    expect($donnees->date_naissance)->toBe('1985-06-15');
    expect($donnees->sexe)->toBe('F');
    expect($donnees->poids)->toBe('65');
    $raw = DB::table('participant_donnees_medicales')->where('id', $donnees->id)->first();
    expect($raw->date_naissance)->not->toBe('1985-06-15');
});

test('deleting participant cascades to donnees medicales', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => Operation::factory()->create(['association_id' => $this->association->id])->id,
        'date_inscription' => now()->toDateString(),
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1990-01-01',
        'sexe' => 'M',
        'poids' => '80',
    ]);
    $participant->delete();
    expect(ParticipantDonneesMedicales::count())->toBe(0);
});

test('user peut_voir_donnees_sensibles defaults to false', function (): void {
    $user = User::factory()->create();
    expect($user->peut_voir_donnees_sensibles)->toBeFalse();
});

test('participant donnees medicales has unique constraint on participant_id', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => Operation::factory()->create(['association_id' => $this->association->id])->id,
        'date_inscription' => now()->toDateString(),
    ]);
    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);
    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);
})->throws(QueryException::class);

test('medecin and therapeute fields are encrypted in database', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => Operation::factory()->create(['association_id' => $this->association->id])->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $donnees = ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'medecin_nom' => 'Martin',
        'medecin_prenom' => 'Jean',
        'medecin_telephone' => '0601020304',
        'medecin_email' => 'martin@exemple.fr',
        'medecin_adresse' => '1 rue de la Paix',
        'therapeute_nom' => 'Dupont',
        'therapeute_prenom' => 'Marie',
        'therapeute_telephone' => '0611223344',
        'therapeute_email' => 'dupont@exemple.fr',
        'therapeute_adresse' => '2 avenue des Fleurs',
    ]);
    $donnees->refresh();
    expect($donnees->medecin_nom)->toBe('Martin');
    expect($donnees->medecin_prenom)->toBe('Jean');
    expect($donnees->therapeute_nom)->toBe('Dupont');
    expect($donnees->therapeute_email)->toBe('dupont@exemple.fr');
    $raw = DB::table('participant_donnees_medicales')->where('id', $donnees->id)->first();
    expect($raw->medecin_nom)->not->toBe('Martin');
});
