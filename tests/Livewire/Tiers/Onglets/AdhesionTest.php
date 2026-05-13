<?php

declare(strict_types=1);

use App\Livewire\Tiers\Onglets\Adhesion;
use App\Models\Adhesion as AdhesionModel;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('Vue affiche les adhésions dans l\'ordre exercice desc', function (): void {
    $tiers = Tiers::factory()->create();

    AdhesionModel::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2024]);
    AdhesionModel::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2025]);

    $html = Livewire::test(Adhesion::class, ['tiers' => $tiers])->html();

    // 2025 doit apparaître avant 2024
    expect(strpos($html, 'Ex. 2025-'))->toBeLessThan(strpos($html, 'Ex. 2024-'));
});

it('Badge "Cotisation" sur ligne payée', function (): void {
    $tiers = Tiers::factory()->create();
    AdhesionModel::factory()->payee()->create(['tiers_id' => $tiers->id]);

    Livewire::test(Adhesion::class, ['tiers' => $tiers])
        ->assertSee('Cotisation')
        ->assertDontSee('Offerte');
});

it('Badge "Offerte" + motif sur ligne gratuite', function (): void {
    $tiers = Tiers::factory()->create();
    AdhesionModel::factory()->create([
        'tiers_id' => $tiers->id,
        'notes' => 'Membre d\'honneur',
    ]);

    Livewire::test(Adhesion::class, ['tiers' => $tiers])
        ->assertSee('Offerte')
        ->assertSee('Membre d\'honneur');
});

it('Montant affiché sur ligne payée', function (): void {
    $tiers = Tiers::factory()->create();
    AdhesionModel::factory()->payee()->create(['tiers_id' => $tiers->id]);

    $html = Livewire::test(Adhesion::class, ['tiers' => $tiers])->html();

    // Montant formaté avec virgule (nombre non nul attendu depuis TransactionFactory)
    expect($html)->toContain('€');
});
