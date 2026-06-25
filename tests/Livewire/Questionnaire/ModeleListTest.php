<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ModeleList;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('crée un modèle via la modale', function (): void {
    Livewire::test(ModeleList::class)
        ->call('openCreate')
        ->set('titre_interne', 'Satisfaction parcours')
        ->set('titre_affiche', 'Votre avis')
        ->call('save')
        ->assertHasNoErrors();

    expect(QuestionnaireTemplate::where('titre_interne', 'Satisfaction parcours')->exists())->toBeTrue();
});

it('les réglages par défaut sont true à la création', function (): void {
    $component = Livewire::test(ModeleList::class)->call('openCreate');

    $component->assertSet('anonymise', true)
        ->assertSet('autoriserRetour', true)
        ->assertSet('afficherProgression', true);
});

it('persiste les 3 réglages booléens à la création', function (): void {
    Livewire::test(ModeleList::class)
        ->call('openCreate')
        ->set('titre_interne', 'Test réglages')
        ->set('titre_affiche', 'Avis')
        ->set('anonymise', false)
        ->set('autoriserRetour', false)
        ->set('afficherProgression', false)
        ->call('save')
        ->assertHasNoErrors();

    $m = QuestionnaireTemplate::where('titre_interne', 'Test réglages')->firstOrFail();
    expect($m->anonymise)->toBeFalse();
    expect($m->autoriser_retour)->toBeFalse();
    expect($m->afficher_progression)->toBeFalse();
});

it('charge les réglages existants lors de openEdit', function (): void {
    $t = QuestionnaireTemplate::factory()->create([
        'anonymise' => false,
        'autoriser_retour' => true,
        'afficher_progression' => false,
    ]);

    Livewire::test(ModeleList::class)
        ->call('openEdit', $t->id)
        ->assertSet('anonymise', false)
        ->assertSet('autoriserRetour', true)
        ->assertSet('afficherProgression', false);
});

it('bascule le statut actif', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['actif' => true]);

    Livewire::test(ModeleList::class)->call('toggleActif', $t->id);

    expect($t->fresh()->actif)->toBeFalse();
});
