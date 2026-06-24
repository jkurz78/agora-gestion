<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ModeleTextes;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('affiche intro et remerciement courants dans le formulaire', function (): void {
    $t = QuestionnaireTemplate::factory()->create([
        'intro' => '<p>Bienvenue !</p>',
        'remerciement' => '<p>Merci !</p>',
        'titre_affiche' => 'Sondage juin',
    ]);

    Livewire::test(ModeleTextes::class, ['template' => $t])
        ->assertSet('intro', '<p>Bienvenue !</p>')
        ->assertSet('remerciement', '<p>Merci !</p>')
        ->assertSet('titreAffiche', 'Sondage juin');
});

it('enregistrer() persiste intro assainie (garde {prenom}, supprime script)', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleTextes::class, ['template' => $t])
        ->set('intro', '<p>Bonjour {prenom}</p><script>alert(1)</script>')
        ->set('remerciement', '<p>Merci !</p>')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $t->refresh();
    expect($t->intro)->toContain('Bonjour {prenom}');
    expect($t->intro)->not->toContain('<script>');
    expect($t->remerciement)->toContain('Merci');
});

it('enregistrer() met intro à null si vide', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['intro' => '<p>Ancien</p>']);

    Livewire::test(ModeleTextes::class, ['template' => $t])
        ->set('intro', '')
        ->call('enregistrer');

    expect($t->fresh()->intro)->toBeNull();
});

it('enregistrer() persiste titre_affiche en texte brut', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Avant']);

    Livewire::test(ModeleTextes::class, ['template' => $t])
        ->set('titreAffiche', 'Satisfaction {operation}')
        ->call('enregistrer');

    expect($t->fresh()->titre_affiche)->toBe('Satisfaction {operation}');
});
