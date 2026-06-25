<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ModeleInfos;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('monte avec les valeurs du modèle', function (): void {
    $t = QuestionnaireTemplate::factory()->create([
        'titre_interne' => 'Mon modèle',
        'titre_affiche' => 'Votre avis',
        'anonymise' => false,
        'autoriser_retour' => false,
        'afficher_progression' => true,
    ]);

    Livewire::test(ModeleInfos::class, ['template' => $t])
        ->assertSet('titreInterne', 'Mon modèle')
        ->assertSet('titreAffiche', 'Votre avis')
        ->assertSet('anonymise', false)
        ->assertSet('autoriserRetour', false)
        ->assertSet('afficherProgression', true);
});

it('enregistrer persiste les titres et les 3 booléens', function (): void {
    $t = QuestionnaireTemplate::factory()->create([
        'titre_interne' => 'Ancien titre',
        'titre_affiche' => 'Ancien affiché',
        'anonymise' => true,
        'autoriser_retour' => true,
        'afficher_progression' => true,
    ]);

    Livewire::test(ModeleInfos::class, ['template' => $t])
        ->set('titreInterne', 'Nouveau titre')
        ->set('titreAffiche', 'Nouveau affiché')
        ->set('anonymise', false)
        ->set('autoriserRetour', false)
        ->set('afficherProgression', false)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $t->refresh();
    expect($t->titre_interne)->toBe('Nouveau titre');
    expect($t->titre_affiche)->toBe('Nouveau affiché');
    expect($t->anonymise)->toBeFalse();
    expect($t->autoriser_retour)->toBeFalse();
    expect($t->afficher_progression)->toBeFalse();
});

it('enregistrer échoue si titreInterne est vide', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleInfos::class, ['template' => $t])
        ->set('titreInterne', '')
        ->set('titreAffiche', 'Affiché')
        ->call('enregistrer')
        ->assertHasErrors(['titreInterne' => 'required']);
});

it('enregistrer échoue si titreAffiche est vide', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleInfos::class, ['template' => $t])
        ->set('titreInterne', 'Titre')
        ->set('titreAffiche', '')
        ->call('enregistrer')
        ->assertHasErrors(['titreAffiche' => 'required']);
});
