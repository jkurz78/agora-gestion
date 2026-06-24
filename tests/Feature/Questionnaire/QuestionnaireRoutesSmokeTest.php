<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
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

afterEach(function () {
    TenantContext::clear();
});

it('affiche la page liste des modèles (host page x-app-layout)', function (): void {
    QuestionnaireTemplate::factory()->create(['titre_interne' => 'Satisfaction parcours']);

    $this->get(route('questionnaires.modeles.index'))
        ->assertOk()
        ->assertSee('Modèles de questionnaires')
        ->assertSee('Satisfaction parcours');
});

it('affiche la page éditeur de questions (host page x-app-layout)', function (): void {
    $template = QuestionnaireTemplate::factory()->create(['titre_interne' => 'Mon modèle']);

    $this->get(route('questionnaires.modeles.editor', $template))
        ->assertOk()
        ->assertSee('Mon modèle');
});

it('affiche la page Textes du modèle (host page x-app-layout)', function (): void {
    $template = QuestionnaireTemplate::factory()->create([
        'titre_interne' => 'Textes modèle test',
        'intro' => '<p>Intro test</p>',
    ]);

    $this->get(route('questionnaires.modeles.textes', $template))
        ->assertOk()
        ->assertSee('Textes modèle test')
        ->assertSee('Textes du questionnaire');
});

it('affiche la page résultats d une campagne (host page x-app-layout)', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create([
        'titre_affiche' => 'Satisfaction parcours juin',
        'statut' => 'ouverte',
    ]);

    $this->get(route('questionnaires.campagnes.resultats', $campagne))
        ->assertOk()
        ->assertSee('Satisfaction parcours juin');
});

it('exporte le xlsx d une campagne (regression guard)', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create([
        'titre_affiche' => 'Export test',
        'statut' => 'ouverte',
    ]);

    $this->get(route('questionnaires.campagnes.export', $campagne))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
