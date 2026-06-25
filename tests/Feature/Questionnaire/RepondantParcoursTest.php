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
use App\Support\CurrentAssociation;
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

it('affiche 5 inputs radio satisfaction (valeurs 1 à 5) sous forme de smileys', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start'])->assertRedirect();

    $question = $invitation->campaign->questions()->first();
    $response = $this->get("/q/{$clair}?page=1");
    $response->assertOk();

    $fieldName = "q_{$question->id}";
    foreach (range(1, 5) as $val) {
        $response->assertSee("name=\"{$fieldName}\"", false);
        $response->assertSee("value=\"{$val}\"", false);
    }
    // S'assure que le SVG smiley est présent
    $response->assertSee('q-satis-svg', false);
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
    TenantContext::clear();

    $this->get("/q/{$clair}")
        ->assertOk()
        ->assertSee('Bonjour', false)
        ->assertSee('Camille', false)
        ->assertSee('<strong>', false); // HTML rendu, pas échappé
});

it('le parcours enregistre le commentaire de satisfaction', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction', 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $clair = Str::random(48);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$q->id}" => '4', "q_{$q->id}_commentaire" => 'RAS positif']);

    $a = $inv->fresh()->submissions()->first()->answers()->first();
    expect($a->value_integer)->toBe(4);
    expect($a->value_text)->toBe('RAS positif');
});

it('affiche un input hidden pour la question ressenti (sans valeur par défaut)', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Ressenti, 'ordre' => 1, 'obligatoire' => false,
    ]);
    $clair = Str::random(48);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    $response = $this->get("/q/{$clair}?page=1");
    $response->assertOk();

    // Un input hidden nommé q_{id} doit exister (vide tant que non positionné)
    $fieldName = "q_{$q->id}";
    $response->assertSee("name=\"{$fieldName}\"", false);
    // Pas de valeur par défaut : le widget ne préremplit pas 50
    $response->assertSee('Placez le curseur selon votre ressenti', false);
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

it('le bouton Précédent revient en arrière en persistant la saisie', function (): void {
    [$clair, $invitation] = makeOuverteInvitation(); // 1 question satisfaction obligatoire
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // La page question affiche un bouton Précédent.
    $this->get("/q/{$clair}?page=1")->assertOk()->assertSee('Précédent');

    // Précédent depuis la page 1 → persiste la note (sans bloquer) → retour à l'intro (page 0).
    $question = $invitation->campaign->questions()->first();
    $this->post("/q/{$clair}", ['action' => 'prev', 'page' => 1, "q_{$question->id}" => '3'])
        ->assertRedirect(route('questionnaire.show', ['token' => $clair, 'page' => 0]));

    $answer = $invitation->fresh()->submissions()->first()->answers()->first();
    expect($answer->value_integer)->toBe(3); // saisie conservée malgré le retour
});

it('affiche le nom de l association en en-tête du parcours', function (): void {
    [$clair, $invitation] = makeOuverteInvitation();
    $nomAsso = CurrentAssociation::get()->nom; // tenant courant du test
    TenantContext::clear();

    // Le nom de l'association apparaît au-dessus du cadre (en-tête centré).
    $this->get("/q/{$clair}")->assertOk()->assertSee($nomAsso, false);
});

it('afficher_progression=false : la barre de progression est absente de la page question', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'afficher_progression' => false,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => false,
    ]);
    $clair = Str::random(48);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    $this->get("/q/{$clair}?page=1")
        ->assertOk()
        ->assertDontSee('sur ', false); // « Question x sur n » absent
});

it('autoriser_retour=false : le bouton Précédent est absent de la page question', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'autoriser_retour' => false,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => false,
    ]);
    $clair = Str::random(48);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    $this->get("/q/{$clair}?page=1")
        ->assertOk()
        ->assertDontSee('Précédent', false);
});

// ────────────────────────────────────────────────────────────────
// Tests écran-aware (Task 3 — sections légères)
// ────────────────────────────────────────────────────────────────

it('deux questions groupées apparaissent toutes les deux sur une seule page', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte,
        'afficher_progression' => true,
        'anonymise' => true,
    ]);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Première question', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 1, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Deuxième question groupée', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $clair = Str::random(48);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    $response = $this->get("/q/{$clair}?page=1");
    $response->assertOk();

    // Les deux questions doivent être sur la même page (page 1 = seul écran).
    $response->assertSee('Première question', false);
    $response->assertSee('Deuxième question groupée', false);

    // La barre de progression indique 1 seul écran (total = 1).
    $response->assertSee('Page 1 sur 1', false);
});

it('total de la barre de progression = nombre d écrans (et non nombre de questions)', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte,
        'afficher_progression' => true,
        'anonymise' => true,
    ]);
    // Q1 seule sur écran 1, Q2+Q3 groupées sur écran 2 → 2 écrans pour 3 questions.
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 1, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q2', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q3', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 3, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $clair = Str::random(48);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // Écran 1 : « Page 1 sur 2 »
    $this->get("/q/{$clair}?page=1")->assertOk()->assertSee('Page 1 sur 2', false);
    // Écran 2 : « Page 2 sur 2 »
    $this->get("/q/{$clair}?page=2")->assertOk()->assertSee('Page 2 sur 2', false);
});

it('next bloque si une question obligatoire de l écran est vide', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'anonymise' => true,
    ]);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q obligatoire', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 1, 'obligatoire' => true, 'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q optionnelle groupée', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // q1 vide → erreur q_{id}
    $this->from("/q/{$clair}?page=1")
        ->post("/q/{$clair}", [
            'action' => 'next', 'page' => 1,
            "q_{$q1->id}" => '', "q_{$q2->id}" => 'quelque chose',
        ])
        ->assertSessionHasErrors("q_{$q1->id}");

    // Aucune réponse ne doit avoir été persistée.
    expect($invitation->fresh()->submissions()->first()?->answers()->count())->toBe(0);
});

it('next persiste les deux réponses et avance à l écran suivant quand tout est valide', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'anonymise' => true,
    ]);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 1, 'obligatoire' => true, 'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q2 groupée', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    // Second écran indépendant.
    $q3 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q3 second écran', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 3, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // Valider et avancer depuis l'écran 1 (Q1+Q2 groupées).
    $this->post("/q/{$clair}", [
        'action' => 'next', 'page' => 1,
        "q_{$q1->id}" => 'Réponse 1', "q_{$q2->id}" => 'Réponse 2',
    ])->assertRedirect(route('questionnaire.show', ['token' => $clair, 'page' => 2]));

    $submission = $invitation->fresh()->submissions()->first();
    $answers = $submission->answers()->get()->keyBy('campaign_question_id');

    // Les deux réponses de l'écran 1 doivent être persistées.
    expect($answers->get($q1->id)?->value_text)->toBe('Réponse 1');
    expect($answers->get($q2->id)?->value_text)->toBe('Réponse 2');
});

it('prev persiste les saisies de l écran courant et revient en arrière', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte,
    ]);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1 écran 1', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 1, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q2 écran 2', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => true, 'grouper_avec_precedente' => false,
    ]);
    $q3 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q3 groupée avec Q2', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 3, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // Avancer à l'écran 2 (valide).
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$q1->id}" => 'saisie 1']);

    // Précédent depuis l'écran 2 : persiste Q2+Q3 sans bloquer (obligatoire ignoré).
    $this->post("/q/{$clair}", [
        'action' => 'prev', 'page' => 2,
        "q_{$q2->id}" => 'saisie 2', "q_{$q3->id}" => 'saisie 3',
    ])->assertRedirect(route('questionnaire.show', ['token' => $clair, 'page' => 1]));

    $submission = $invitation->fresh()->submissions()->first();
    $answers = $submission->answers()->get()->keyBy('campaign_question_id');

    expect($answers->get($q2->id)?->value_text)->toBe('saisie 2');
    expect($answers->get($q3->id)?->value_text)->toBe('saisie 3');
});

it('une question Information affiche son libellé et son aide sans champ de saisie', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'anonymise' => true,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Section introduction',
        'aide' => 'Texte explicatif de la section.',
        'type' => TypeQuestion::Information,
        'ordre' => 1, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Votre avis', 'type' => TypeQuestion::TexteCourt,
        'ordre' => 2, 'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    $response = $this->get("/q/{$clair}?page=1");
    $response->assertOk();
    // Le libellé et l'aide de la question Information sont affichés.
    $response->assertSee('Section introduction', false);
    $response->assertSee('Texte explicatif de la section.', false);
    // Q2 (TexteCourt groupée sur le même écran) doit avoir un champ de saisie.
    $response->assertSee("name=\"q_{$q2->id}\"", false);
});

it('une question Information ne génère pas de QuestionnaireAnswer', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'anonymise' => true,
    ]);
    $qInfo = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Intertitre', 'type' => TypeQuestion::Information,
        'ordre' => 1, 'obligatoire' => false, 'grouper_avec_precedente' => false,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start']);

    // Soumettre l'écran 1 (qui ne contient que la question Information).
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1]);

    $submission = $invitation->fresh()->submissions()->first();

    // Aucune réponse ne doit être créée pour la question Information.
    expect($submission->answers()->where('campaign_question_id', $qInfo->id)->count())->toBe(0);
});

it('anonymise=false : la dernière question redirige vers merci (sans passer par consentement)', function (): void {
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'statut' => StatutCampagne::Ouverte, 'anonymise' => false, 'remerciement' => 'Merci !',
    ]);
    $question = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);
    $clair = Str::random(48);
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
        'token_hash' => app(QuestionnaireTokenService::class)->hash($clair),
        'statut' => StatutInvitation::NonOuvert,
    ]);
    TenantContext::clear();

    $this->post("/q/{$clair}", ['action' => 'start'])->assertRedirect();

    // Dernière (et seule) question : next → doit aller vers merci, PAS consentement.
    $this->post("/q/{$clair}", ['action' => 'next', 'page' => 1, "q_{$question->id}" => '5'])
        ->assertRedirect(route('questionnaire.merci', ['token' => $clair]));

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
});
