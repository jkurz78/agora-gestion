<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireSubmission;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});
afterEach(fn () => TenantContext::clear());

it('prévisualise un modèle sans rien enregistrer', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Votre avis']);
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['libelle' => 'Note', 'type' => 'satisfaction', 'ordre' => 1]);

    $this->get(route('questionnaires.modeles.apercu', $t))
        ->assertOk()
        ->assertSee('Mode aperçu', false)
        ->assertSee('Votre avis', false);

    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertSee('Note', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
});

it('prévisualise une campagne avec variables d exemple résolues sur l opération', function (): void {
    $op = Operation::factory()->create(['nom' => 'Atelier démo']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['intro' => '<p>Pour {operation}</p>']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['type' => 'texte_court', 'ordre' => 1]);

    $this->get(route('questionnaires.campagnes.apercu', $campagne))
        ->assertOk()
        ->assertSee('Atelier démo', false); // {operation} résolu sur la vraie opération

    expect(QuestionnaireSubmission::count())->toBe(0);
});

it('mémorise les réponses en session lors de la navigation aperçu (modèle) — zéro écriture DB', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Sondage test']);
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['libelle' => 'Satisfaction globale', 'type' => 'satisfaction', 'ordre' => 1]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['libelle' => 'Commentaire libre', 'type' => 'texte_court', 'ordre' => 2]);

    // Arriver sur l'intro (reset session)
    $this->get(route('questionnaires.modeles.apercu', $t))->assertOk();

    // Afficher la question 1
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))->assertOk();

    // Soumettre une réponse satisfaction = 4 et naviguer vers la question 2
    $this->post(route('questionnaires.modeles.apercu.store', $t), [
        'page' => 1,
        'action' => 'next',
        "q_{$q1->id}" => '4',
    ])->assertRedirect(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 2]));

    // Afficher la question 2 (vérifie que la session tourne correctement)
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 2]))->assertOk();

    // Naviguer en arrière depuis la question 2
    $this->post(route('questionnaires.modeles.apercu.store', $t), [
        'page' => 2,
        'action' => 'prev',
        "q_{$q2->id}" => '',
    ])->assertRedirect(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]));

    // La page de la question 1 doit afficher la valeur mémorisée (radio checked="checked" pour valeur 4)
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertSee('value="4"', false)
        ->assertSee('checked', false);

    // Invariant absolu : zéro enregistrement en base
    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('mémorise les réponses en session lors de la navigation aperçu (campagne) — zéro écriture DB', function (): void {
    $op = Operation::factory()->create(['nom' => 'Atelier session']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['titre_affiche' => 'Retour atelier']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create(['libelle' => 'Votre ressenti', 'type' => 'texte_court', 'ordre' => 1]);

    // Intro
    $this->get(route('questionnaires.campagnes.apercu', $campagne))->assertOk();

    // Question 1
    $this->get(route('questionnaires.campagnes.apercu', ['campagne' => $campagne->id, 'page' => 1]))->assertOk();

    // POST réponse + naviguer vers consentement (dernière question)
    $this->post(route('questionnaires.campagnes.apercu.store', $campagne), [
        'page' => 1,
        'action' => 'next',
        "q_{$q->id}" => 'Super atelier !',
    ])->assertRedirect(route('questionnaires.campagnes.apercu', ['campagne' => $campagne->id, 'page' => 'consentement']));

    // Naviguer en arrière depuis le consentement (GET link)
    $this->get(route('questionnaires.campagnes.apercu', ['campagne' => $campagne->id, 'page' => 1]))
        ->assertOk()
        ->assertSee('Super atelier !', false);

    // Invariant absolu : zéro enregistrement en base
    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('efface la session aperçu quand on atteint la page merci', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['type' => 'texte_court', 'ordre' => 1]);

    // Mémoriser une réponse
    $this->post(route('questionnaires.modeles.apercu.store', $t), [
        'page' => 1,
        'action' => 'next',
        "q_{$q->id}" => 'Réponse test',
    ]);

    // Accéder à la page merci (vide la session)
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 'merci']))->assertOk();

    // Retourner à la question 1 — le champ doit être vide (session effacée)
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertDontSee('Réponse test', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('réinitialise la session aperçu quand on revient sur l intro', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['type' => 'texte_court', 'ordre' => 1]);

    // Mémoriser une réponse
    $this->post(route('questionnaires.modeles.apercu.store', $t), [
        'page' => 1,
        'action' => 'next',
        "q_{$q->id}" => 'Ancienne réponse',
    ]);

    // Retour sur l'intro (reset)
    $this->get(route('questionnaires.modeles.apercu', $t))->assertOk();

    // La question 1 doit être vide maintenant
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertDontSee('Ancienne réponse', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});
