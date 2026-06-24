<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\Tiers;
use App\Services\Questionnaire\QuestionnaireVariableResolver;

it('résout les variables depuis une invitation réelle', function (): void {
    $tiers = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Durand']);
    $op = Operation::factory()->create(['nom' => 'Atelier sophro']);
    $participant = Participant::factory()->create(['operation_id' => $op->id, 'tiers_id' => $tiers->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
    ]);

    $vars = app(QuestionnaireVariableResolver::class)->pour($invitation);

    expect($vars['{prenom}'])->toBe('Marie');
    expect($vars['{operation}'])->toBe('Atelier sophro');
    expect($vars)->not->toHaveKey('{lien_questionnaire}'); // pas de lien sans avecLien
});

it('inclut le lien quand demandé', function (): void {
    $invitation = QuestionnaireInvitation::factory()->create();
    $vars = app(QuestionnaireVariableResolver::class)->pour($invitation, avecLien: true);

    expect($vars['{lien_questionnaire}'])->toBe($invitation->lienReponse());
});

it('produit des valeurs d exemple sans invitation', function (): void {
    $vars = app(QuestionnaireVariableResolver::class)->exemple();

    expect($vars['{prenom}'])->toBe('Jean');
    expect($vars['{operation}'])->toBe('Mon opération');
});

it('échappe les valeurs lors du remplacement (anti-injection)', function (): void {
    $resolver = app(QuestionnaireVariableResolver::class);
    $html = $resolver->remplacer('Bonjour {prenom}', ['{prenom}' => '<script>alert(1)</script>']);

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
});

it('résout les variables de parité email (salutation, email, table_seances)', function (): void {
    $tiers = Tiers::factory()->create([
        'prenom' => 'Marie', 'nom' => 'Durand', 'email' => 'marie@example.test', 'civilite' => null,
    ]);
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id, 'tiers_id' => $tiers->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();
    $invitation = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create([
        'participant_id' => $participant->id,
    ]);

    $vars = app(QuestionnaireVariableResolver::class)->pour($invitation);

    expect($vars['{email_participant}'])->toBe('marie@example.test');
    expect($vars['{salutation}'])->toBe('Madame, Monsieur'); // civilité nulle → fallback
    expect($vars)->toHaveKey('{politesse_nom}');
    expect($vars)->toHaveKey('{table_seances}'); // '' quand l'opération n'a pas de séances
});

it('n échappe pas le HTML de table_seances mais échappe les scalaires', function (): void {
    $resolver = app(QuestionnaireVariableResolver::class);
    $html = $resolver->remplacer('{table_seances} — {prenom}', [
        '{table_seances}' => '<table><tr><td>S1</td></tr></table>',
        '{prenom}' => '<b>x</b>',
    ]);

    expect($html)->toContain('<table>');       // HTML interne inséré brut
    expect($html)->not->toContain('<b>x</b>'); // scalaire échappé
    expect($html)->toContain('&lt;b&gt;');
});
