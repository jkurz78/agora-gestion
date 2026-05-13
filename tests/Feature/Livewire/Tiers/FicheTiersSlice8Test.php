<?php

declare(strict_types=1);

use App\Livewire\Tiers\FicheTiers;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('cache les onglets Communications et Documents si tiers sans email ni doc', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(FicheTiers::class, ['tiers' => $tiers])
        ->assertDontSee('Communications')
        ->assertDontSee('Documents');
});

it('affiche l\'onglet Communications avec compteur si tiers a des emails', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->count(3)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    Livewire::test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Communications')
        ->assertSee('(3)')
        ->assertDontSee('Documents');
});

it('affiche l\'onglet Documents si tiers a des factures', function (): void {
    $tiers = Tiers::factory()->create();
    Facture::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Documents')
        ->assertDontSee('Communications');
});

it('charge le composant Communications quand onglet=communications', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Hello fiche',
    ]);

    Livewire::test(FicheTiers::class, ['tiers' => $tiers])
        ->set('onglet', 'communications')
        ->assertSee('Communications');
});
