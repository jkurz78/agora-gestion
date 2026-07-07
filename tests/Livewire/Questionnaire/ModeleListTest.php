<?php

declare(strict_types=1);

use App\Livewire\Questionnaire\ModeleList;
use App\Models\QuestionnaireTemplate;
use Livewire\Livewire;

it('crée un modèle via la modale et redirige vers Informations', function (): void {
    Livewire::test(ModeleList::class)
        ->call('openCreate')
        ->set('titre_interne', 'Satisfaction parcours')
        ->set('titre_affiche', 'Votre avis')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirectToRoute('questionnaires.modeles.infos',
            QuestionnaireTemplate::where('titre_interne', 'Satisfaction parcours')->firstOrFail()
        );

    expect(QuestionnaireTemplate::where('titre_interne', 'Satisfaction parcours')->exists())->toBeTrue();
});

it('bascule le statut actif', function (): void {
    $t = QuestionnaireTemplate::factory()->create(['actif' => true]);

    Livewire::test(ModeleList::class)->call('toggleActif', $t->id);

    expect($t->fresh()->actif)->toBeFalse();
});

it('supprime un modèle', function (): void {
    $t = QuestionnaireTemplate::factory()->create();

    Livewire::test(ModeleList::class)->call('supprimer', $t->id);

    expect(QuestionnaireTemplate::find($t->id))->toBeNull();
});
