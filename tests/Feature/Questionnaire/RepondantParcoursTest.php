<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\StatutInvitation;
use App\Enums\TypeQuestion;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireTokenService;
use App\Tenant\TenantContext;
use Illuminate\Support\Str;

function makeOuverteInvitation(): array
{
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'remerciement' => 'Merci !',
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);

    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);

    return [$clair, $invitation];
}

it('affiche l intro et résout le tenant sans contexte préalable', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    // Récupère le titre AVANT de vider le contexte (TenantScope actif ici)
    $titreAffiche = $invitation->campaign->titre_affiche;

    TenantContext::clear(); // route publique : aucun tenant booté

    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee($titreAffiche, false);
});

it('bloque la sauvegarde d une question obligatoire vide puis finalise', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    TenantContext::clear();

    // page 0 = intro → submit pour démarrer
    $this->post("/q/{$clair}", ['action' => 'start'])->assertRedirect();

    // question obligatoire vide → re-affiche avec erreur
    $question = $invitation->campaign->questions()->first();
    $this->from("/q/{$clair}?page=1")
        ->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$question->id}" => ''])
        ->assertSessionHasErrors();

    // valeur fournie → avance
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$question->id}" => '5'])
        ->assertRedirect();

    // consentement + finalisation
    $this->post("/q/{$clair}", ['action' => 'finish', 'accepte_contact' => '0'])
        ->assertRedirect(route('questionnaire.merci', ['token' => $clair]));

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
});

it('affiche déjà répondu si l invitation est soumise', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    $invitation->update(['statut' => StatutInvitation::Soumis]);
    TenantContext::clear();

    $this->get("/q/{$clair}")->assertSee('déjà', false);
});

it('affiche l intro en HTML avec variables résolues', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    $invitation->campaign->update(['intro' => '<p>Bonjour <strong>{prenom}</strong></p>']);
    $invitation->participant->tiers->update(['prenom' => 'Camille']);
    \App\Tenant\TenantContext::clear();

    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee('Bonjour', false)
        ->assertSee('Camille', false)
        ->assertSee('<strong>', false); // HTML rendu, pas échappé
});

it('le parcours enregistre le commentaire de satisfaction', function (): void {
    $op = \App\Models\Operation::factory()->create();
    $participant = \App\Models\Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = \App\Models\QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction', 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $clair = \Illuminate\Support\Str::random(48);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(\App\Services\Questionnaire\QuestionnaireTokenService::class)->hash($clair),
    ]);
    \App\Tenant\TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$q->id}" => '4', "q_{$q->id}_commentaire" => 'RAS positif']);

    $a = $inv->fresh()->submissions()->first()->answers()->first();
    expect($a->value_integer)->toBe(4);
    expect($a->value_text)->toBe('RAS positif');
});

it('résout le tenant de l invitation même si un autre tenant est déjà booté', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    // Capturés tant que le tenant de l'invitation est le contexte courant.
    $invitationAssoId = (int) $invitation->association_id;
    $titre = $invitation->campaign->titre_affiche;

    // Un AUTRE tenant est le contexte courant au moment de la requête publique.
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    // La résolution par hash (withoutGlobalScope + boot) sert l'association de
    // l'invitation, jamais celle qui était bootée — isolation fail-closed publique.
    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee($titre, false);

    expect((int) TenantContext::currentId())->toBe($invitationAssoId);
    expect($invitationAssoId)->not->toBe((int) $autre->id);
});
