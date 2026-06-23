<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Livewire\Questionnaire\OperationQuestionnaires;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireTemplate;
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
