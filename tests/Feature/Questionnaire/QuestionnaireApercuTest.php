<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
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
