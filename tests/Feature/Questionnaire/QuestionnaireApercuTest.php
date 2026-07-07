<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
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

it('anonymise=false : l aperçu passe directement à merci depuis la dernière question (zéro DB)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => false]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'texte_court', 'ordre' => 1, 'obligatoire' => false,
    ]);
    $store = route('questionnaires.campagnes.apercu.store', $campagne);
    $base = route('questionnaires.campagnes.apercu', $campagne);

    // Dernière (et seule) question, suivant → merci directement (pas consentement).
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => 'ok'])
        ->assertRedirect($base.'?page=merci');

    // Zéro écriture en base.
    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('l aperçu bloque sur une question obligatoire vide (comme le parcours réel)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'texte_court', 'ordre' => 1, 'obligatoire' => true,
    ]);
    $store = route('questionnaires.campagnes.apercu.store', $campagne);
    $base = route('questionnaires.campagnes.apercu', $campagne);

    // Suivant avec une valeur vide → reste sur la page 1 avec une erreur keyed q_{id}.
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => ''])
        ->assertRedirect($base.'?page=1')
        ->assertSessionHasErrors("q_{$q->id}");

    // Avec une valeur → avance (1 seule question → consentement).
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => 'ok'])
        ->assertRedirect($base.'?page=consentement');

    // Toujours zéro écriture en base.
    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('l aperçu écran-aware : 2 questions groupées apparaissent sur la même page', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Sondage groupé']);
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Première question', 'type' => 'texte_court', 'ordre' => 1,
        'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Deuxième question', 'type' => 'texte_court', 'ordre' => 2,
        'grouper_avec_precedente' => true,
    ]);

    // Page 1 doit afficher les deux libellés (un seul écran de 2 questions).
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertOk()
        ->assertSee('Première question', false)
        ->assertSee('Deuxième question', false);

    // Barre de progression affiche « 1 sur 1 » (un seul écran).
    $this->get(route('questionnaires.modeles.apercu', ['template' => $t->id, 'page' => 1]))
        ->assertSee('Page 1 sur 1', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('l aperçu écran-aware : next avec une question obligatoire vide redirige sur la même page avec erreur q_{id}', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'type' => 'texte_court', 'ordre' => 1, 'obligatoire' => true,
        'grouper_avec_precedente' => false,
    ]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'type' => 'texte_court', 'ordre' => 2, 'obligatoire' => false,
        'grouper_avec_precedente' => true,
    ]);
    $store = route('questionnaires.modeles.apercu.store', $t);
    $base = route('questionnaires.modeles.apercu', $t);

    // q1 vide → erreur sur q_{q1->id}, reste page 1, zéro DB.
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q1->id}" => '', "q_{$q2->id}" => 'ok'])
        ->assertRedirect($base.'?page=1')
        ->assertSessionHasErrors("q_{$q1->id}");

    // Les deux renseignées → avance vers consentement (1 seul écran → fin).
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q1->id}" => 'réponse', "q_{$q2->id}" => 'ok'])
        ->assertRedirect($base.'?page=consentement');

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('l aperçu écran-aware : next puis prev conserve les réponses des deux questions de l écran', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['anonymise' => true]);
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Question A', 'type' => 'texte_court', 'ordre' => 1,
        'grouper_avec_precedente' => false, 'obligatoire' => false,
    ]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Question B', 'type' => 'texte_court', 'ordre' => 2,
        'grouper_avec_precedente' => false, 'obligatoire' => false,
    ]);
    $store = route('questionnaires.modeles.apercu.store', $t);
    $base = route('questionnaires.modeles.apercu', $t);

    // Remplir écran 1 (q1) et avancer.
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q1->id}" => 'valeur-A'])
        ->assertRedirect($base.'?page=2');

    // Remplir écran 2 (q2) et reculer.
    $this->post($store, ['action' => 'prev', 'page' => 2, "q_{$q2->id}" => 'valeur-B'])
        ->assertRedirect($base.'?page=1');

    // Revenir sur l'écran 1 : valeur-A toujours là.
    $this->get($base.'?page=1')
        ->assertOk()
        ->assertSee('valeur-A', false);

    // Revenir sur l'écran 2 : valeur-B toujours là.
    $this->get($base.'?page=2')
        ->assertOk()
        ->assertSee('valeur-B', false);

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

// ── SatisfactionTexteLong — aperçu ──────────────────────────────

it('STL aperçu : next bloque si la note obligatoire est vide (zéro DB)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => true]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction_texte_long', 'ordre' => 1,
        'obligatoire' => true, 'config' => ['texte_obligatoire' => false],
    ]);
    $store = route('questionnaires.campagnes.apercu.store', $campagne);
    $base = route('questionnaires.campagnes.apercu', $campagne);

    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => '', "q_{$q->id}_commentaire" => 'texte'])
        ->assertRedirect($base.'?page=1')
        ->assertSessionHasErrors("q_{$q->id}");

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('STL aperçu : next bloque si le texte obligatoire est vide (zéro DB)', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => true]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction_texte_long', 'ordre' => 1,
        'obligatoire' => false, 'config' => ['texte_obligatoire' => true],
    ]);
    $store = route('questionnaires.campagnes.apercu.store', $campagne);
    $base = route('questionnaires.campagnes.apercu', $campagne);

    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => '3', "q_{$q->id}_commentaire" => ''])
        ->assertRedirect($base.'?page=1')
        ->assertSessionHasErrors("q_{$q->id}_commentaire");

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('STL aperçu : next avec note et texte valides avance sans écriture DB', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => true]);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => 'satisfaction_texte_long', 'ordre' => 1,
        'obligatoire' => true, 'config' => ['texte_obligatoire' => true],
    ]);
    $store = route('questionnaires.campagnes.apercu.store', $campagne);
    $base = route('questionnaires.campagnes.apercu', $campagne);

    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$q->id}" => '5', "q_{$q->id}_commentaire" => 'Super'])
        ->assertRedirect($base.'?page=consentement');

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});

it('l aperçu écran-aware : une question Information s affiche sans input et n est jamais obligatoire', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $qInfo = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Titre de section', 'type' => 'information', 'ordre' => 1,
        'aide' => 'Texte explicatif ici.', 'obligatoire' => true, // obligatoire ignoré pour Information
        'grouper_avec_precedente' => false,
    ]);
    $qTexte = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Votre avis', 'type' => 'texte_court', 'ordre' => 2,
        'obligatoire' => false, 'grouper_avec_precedente' => true,
    ]);
    $store = route('questionnaires.modeles.apercu.store', $t);
    $base = route('questionnaires.modeles.apercu', $t);

    // Les deux sur la même page (grouper_avec_precedente = true sur qTexte).
    $response = $this->get($base.'?page=1');
    $response->assertOk()
        ->assertSee('Titre de section', false)
        ->assertSee('Texte explicatif ici.', false)
        ->assertSee('Votre avis', false);

    // POST sans rien remplir → pas d'erreur sur qInfo (Information ignoré).
    $this->post($store, ['action' => 'next', 'page' => 1, "q_{$qTexte->id}" => ''])
        ->assertRedirect($base.'?page=consentement'); // 1 seul écran, non obligatoire → avance

    expect(QuestionnaireSubmission::count())->toBe(0);
    expect(QuestionnaireAnswer::count())->toBe(0);
});
