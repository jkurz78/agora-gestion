<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\EnvoiCompose;
use App\Mail\QuestionnaireInvitationMail;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('envoie les invitations aux participants sélectionnés', function (): void {
    Mail::fake();
    $op = Operation::factory()->create();
    $p = Participant::factory()->create(['operation_id' => $op->id]);
    $p->tiers->update(['email' => 'p@example.test']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    Livewire::test(EnvoiCompose::class, ['campagne' => $campagne])
        ->set('objet', 'Votre avis')
        ->set('corps', 'Lien : {lien_questionnaire}')
        ->set('selectedParticipants', [$p->id])
        ->call('envoyer')
        ->assertHasNoErrors();

    Mail::assertSent(QuestionnaireInvitationMail::class, 1);
});
