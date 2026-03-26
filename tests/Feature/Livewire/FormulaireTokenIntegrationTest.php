<?php

declare(strict_types=1);

use App\Livewire\ParticipantTable;
use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
    $this->tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

it('shows link icon when participant has no token', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSeeHtml('bi-link-45deg');
});

it('generates a token and opens modal', function () {
    $component = Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('genererToken', $this->participant->id);

    $component->assertSet('showTokenModal', true)
        ->assertNotSet('tokenCode', null)
        ->assertNotSet('tokenUrl', null);

    expect(FormulaireToken::where('participant_id', $this->participant->id)->exists())->toBeTrue();
});

it('shows pending badge after token is generated', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => now()->addDays(7)->toDateString(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('En attente');
});

it('shows filled badge when token is used', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => now()->addDays(7)->toDateString(),
        'rempli_at' => now(),
        'rempli_ip' => '127.0.0.1',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Rempli');
});

it('shows expired badge when token is expired', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => now()->subDay()->toDateString(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSeeHtml('Expiré');
});

it('can open existing token modal', function () {
    $token = FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => now()->addDays(7)->toDateString(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('ouvrirToken', $this->participant->id)
        ->assertSet('showTokenModal', true)
        ->assertSet('tokenCode', 'ABCD-EFGH');
});

it('can regenerate token with custom date', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('genererToken', $this->participant->id)
        ->set('tokenExpireAt', '2026-12-31')
        ->call('genererTokenAvecDate');

    $token = FormulaireToken::where('participant_id', $this->participant->id)->first();
    expect($token->expire_at->format('Y-m-d'))->toBe('2026-12-31');
});

it('shows Formulaire column header', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Formulaire');
});
