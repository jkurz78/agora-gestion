<?php

declare(strict_types=1);

use App\Livewire\OperationDetail;
use App\Models\Operation;
use App\Models\User;
use Livewire\Livewire;

it('ouvre l onglet questionnaires de la fiche opération via ?tab=', function (): void {
    $op = Operation::factory()->create();

    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['tab' => 'questionnaires'])
        ->test(OperationDetail::class, ['operation' => $op])
        ->assertSet('activeTab', 'questionnaires');
});
