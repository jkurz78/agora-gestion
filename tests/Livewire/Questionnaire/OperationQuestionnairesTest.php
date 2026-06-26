<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\StatutInvitation;
use App\Livewire\Questionnaire\OperationQuestionnaires;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaire\QuestionnaireTokenService;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('crée une campagne depuis un modèle et génère les invitations des participants choisis', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $modele = QuestionnaireTemplate::factory()->create();

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->set('selectedTemplateId', $modele->id)
        ->set('selectedParticipants', [$p1->id, $p2->id])
        ->call('creerCampagne')
        ->assertHasNoErrors();

    $op->refresh();
    expect($op->questionnaireCampaigns)->toHaveCount(1);
    $campagne = $op->questionnaireCampaigns->first();
    expect($campagne->statut)->toBe(StatutCampagne::Brouillon);
    expect($campagne->invitations)->toHaveCount(2);
});

it('ouvre une campagne brouillon', function (): void {
    $op = Operation::factory()->create();
    $modele = QuestionnaireTemplate::factory()->create();
    $component = Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->set('selectedTemplateId', $modele->id)
        ->set('selectedParticipants', [])
        ->call('creerCampagne');

    $campagne = $op->fresh()->questionnaireCampaigns->first();
    $component->call('ouvrir', $campagne->id);

    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
});

it('affiche le titre_affiche de la campagne dans la liste', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Évaluation de la formation',
        'statut' => StatutCampagne::Brouillon,
    ]);

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->assertSee('Évaluation de la formation');
});

it('affiche le bouton Lancer et pas Ouvrir', function (): void {
    $op = Operation::factory()->create();
    QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Brouillon,
    ]);

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->assertSee('Lancer')
        ->assertDontSee('Ouvrir');
});

it('permet à l admin de rouvrir une invitation soumise', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::Soumis,
    ]);

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->call('rouvrirInvitation', $invitation->id);

    expect($invitation->fresh()->statut->value)->toBe('commence');
});
