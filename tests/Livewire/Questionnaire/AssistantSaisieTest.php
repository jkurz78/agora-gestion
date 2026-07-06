<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Livewire\Questionnaire\AssistantSaisie;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Models\User;
use Livewire\Livewire;

function makeAssistantFixture(): array
{
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Question satisfaction', 'type' => 'satisfaction', 'ordre' => 1, 'obligatoire' => true,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', 'assistant-test'),
        'token_chiffre' => 'assistant-test',
        'code_court' => 'ASST1234',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    $scan = QuestionnairePaperScan::factory()->create([
        'association_id' => $campagne->association_id,
        'campaign_id' => $campagne->id,
        'invitation_id' => $invitation->id,
        'source' => 'upload',
        'qr_statut' => 'detecte',
        'statut' => 'rattache',
    ]);

    $draft = QuestionnaireOcrDraft::factory()->create([
        'association_id' => $campagne->association_id,
        'scan_id' => $scan->id,
        'invitation_id' => $invitation->id,
        'payload' => [(string) $q1->id => ['value' => 4, 'confidence' => 0.85]],
        'statut' => 'brouillon',
    ]);

    return compact('campagne', 'q1', 'invitation', 'scan', 'draft');
}

it('préremplie les valeurs depuis le payload OCR', function (): void {
    ['scan' => $scan, 'q1' => $q1] = makeAssistantFixture();

    Livewire::test(AssistantSaisie::class, ['scan' => $scan])
        ->assertSet('valeurs.'.$q1->id, 4);
});

it('valider crée une soumission papier et marque le draft validé', function (): void {
    ['scan' => $scan, 'q1' => $q1, 'draft' => $draft, 'invitation' => $invitation] = makeAssistantFixture();

    $cible = route('questionnaires.campagnes.show', ['campagne' => $scan->campaign_id, 'tab' => 'scans']);

    $this->actingAs(User::factory()->create());

    Livewire::test(AssistantSaisie::class, ['scan' => $scan])
        ->set('valeurs.'.$q1->id, 5)
        ->set('accepteContact', false)
        ->call('valider')
        ->assertRedirect($cible);

    expect($draft->fresh()->statut)->toBe('valide');
    expect($scan->fresh()->statut)->toBe('traite');
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);

    // Redirect direct (sans hop 302 intermédiaire) : le flash survit jusqu'à la fiche.
    $this->get($cible)->assertSee('Réponse papier enregistrée.');
});

it('ignorer marque le draft rejeté et le scan ignoré', function (): void {
    ['scan' => $scan, 'draft' => $draft] = makeAssistantFixture();

    Livewire::test(AssistantSaisie::class, ['scan' => $scan])
        ->call('ignorer')
        ->assertRedirect(route('questionnaires.campagnes.show', ['campagne' => $scan->campaign_id, 'tab' => 'scans']));

    expect($draft->fresh()->statut)->toBe('rejete');
    expect($scan->fresh()->statut)->toBe('ignore');
});
