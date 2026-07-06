<?php

declare(strict_types=1);

use App\Livewire\OperationDetail;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\User;
use Livewire\Livewire;

it('ouvre l onglet questionnaires de la fiche opération via ?tab=', function (): void {
    $op = Operation::factory()->create();

    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['tab' => 'questionnaires'])
        ->test(OperationDetail::class, ['operation' => $op])
        ->assertSet('activeTab', 'questionnaires');
});

it('redirige l ancienne route résultats vers la fiche', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    $this->actingAs(User::factory()->create())
        ->get(route('questionnaires.campagnes.resultats', $campagne))
        ->assertRedirect(route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'resultats']));
});

it('redirige l ancienne route scans vers la fiche', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    $this->actingAs(User::factory()->create())
        ->get(route('questionnaires.campagnes.scans', $campagne))
        ->assertRedirect(route('questionnaires.campagnes.show', ['campagne' => $campagne, 'tab' => 'scans']));
});
