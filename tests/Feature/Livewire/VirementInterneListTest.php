<?php

declare(strict_types=1);

use App\Livewire\VirementInterneList;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Models\VirementInterne;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->source = CompteBancaire::factory()->create();
    $this->destination = CompteBancaire::factory()->create();
});

it('n\'affiche pas de colonne Notes dans l\'en-tête', function () {
    Livewire::test(VirementInterneList::class)
        ->assertDontSee('Notes');
});

it('affiche bi-chat-left-text si le virement a des notes', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'date'                  => now(),
        'notes'                 => 'Provision pour charges Q4',
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertSeeHtml('bi bi-chat-left-text')
        ->assertSeeHtml('title="Provision pour charges Q4"');
});

it('n\'affiche pas bi-chat-left-text si notes est null', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'date'                  => now(),
        'notes'                 => null,
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertDontSeeHtml('bi bi-chat-left-text');
});

it('n\'affiche pas bi-chat-left-text si notes est une chaîne vide', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'date'                  => now(),
        'notes'                 => '',
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertDontSeeHtml('bi bi-chat-left-text');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    VirementInterne::factory()->create([
        'compte_source_id'      => $this->source->id,
        'compte_destination_id' => $this->destination->id,
        'saisi_par'             => $this->user->id,
        'date'                  => now(),
    ]);

    Livewire::test(VirementInterneList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
