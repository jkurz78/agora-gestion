<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Livewire\Questionnaire\ModeleEditor;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use Livewire\Livewire;

it('ajoute une question typée au modèle', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('obligatoire', true)
        ->call('ajouterQuestion')
        ->assertHasNoErrors();

    $q = $t->questions()->first();
    expect($q->libelle)->toBe('Note globale');
    expect($q->type)->toBe(TypeQuestion::Satisfaction);
    expect($q->ordre)->toBe(1);
    expect($q->obligatoire)->toBeTrue();
});

it('génère une valeur technique stable pour chaque option de choix unique', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Comment avez-vous connu ?')
        ->set('type', TypeQuestion::ChoixUnique->value)
        ->set('optionsBrut', "Bouche à oreille\nRéseaux sociaux\nAffiche")
        ->call('ajouterQuestion');

    $opts = $t->questions()->first()->options();
    expect($opts)->toHaveCount(3);
    expect($opts[0]['libelle'])->toBe('Bouche à oreille');
    expect($opts[0]['valeur'])->not->toBe('');         // valeur technique générée
    expect($opts[0]['valeur'])->toBe($opts[0]['valeur']); // stable
});

it('réordonne les questions', function (): void {
    $t = QuestionnaireTemplate::factory()->create();
    $q1 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 1]);
    $q2 = QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create(['ordre' => 2]);

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->call('monter', $q2->id);

    expect($q2->fresh()->ordre)->toBe(1);
    expect($q1->fresh()->ordre)->toBe(2);
});

it('active un commentaire optionnel sur une question satisfaction', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleEditor::class, ['template' => $t])
        ->set('libelle', 'Note globale')
        ->set('type', TypeQuestion::Satisfaction->value)
        ->set('commentaire', true)
        ->set('commentaireLibelle', 'Pourquoi cette note ?')
        ->call('ajouterQuestion');

    $q = $t->questions()->first();
    expect($q->config['commentaire'])->toBeTrue();
    expect($q->config['commentaire_libelle'])->toBe('Pourquoi cette note ?');
});

// Note: la persistance intro/remerciement est désormais gérée par ModeleTextes
// (écran "Textes" séparé). Les tests correspondants sont dans ModeleTextesTest.
