<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Livewire\Questionnaire\OperationQuestionnaires;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('crée une campagne depuis un modèle et génère les invitations des participants choisis', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $modele = QuestionnaireTemplate::factory()->create();

    $component = Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->set('selectedTemplateId', $modele->id)
        ->set('selectedParticipants', [$p1->id, $p2->id])
        ->call('creerCampagne')
        ->assertHasNoErrors();

    $op->refresh();
    expect($op->questionnaireCampaigns)->toHaveCount(1);
    $campagne = $op->questionnaireCampaigns->first();
    expect($campagne->statut)->toBe(StatutCampagne::Brouillon);
    expect($campagne->invitations)->toHaveCount(2);

    // La création débouche directement sur la fiche campagne.
    $component->assertRedirect(route('questionnaires.campagnes.show', $campagne));
});

it('affiche le titre_affiche de la campagne avec un lien vers la fiche', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Évaluation de la formation',
        'statut' => StatutCampagne::Brouillon,
    ]);

    Livewire::test(OperationQuestionnaires::class, ['operation' => $op])
        ->assertSee('Évaluation de la formation')
        ->assertSee(route('questionnaires.campagnes.show', $campagne));
});

it('n expose plus toggleEnvoi dans le composant liste', function (): void {
    $op = Operation::factory()->create();

    $component = Livewire::test(OperationQuestionnaires::class, ['operation' => $op]);

    expect(method_exists($component->instance(), 'toggleEnvoi'))->toBeFalse();
});

it('n expose plus toggleImpression dans le composant liste', function (): void {
    $op = Operation::factory()->create();

    $component = Livewire::test(OperationQuestionnaires::class, ['operation' => $op]);

    expect(method_exists($component->instance(), 'toggleImpression'))->toBeFalse();
});
