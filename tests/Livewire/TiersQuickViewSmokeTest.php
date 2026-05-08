<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\TiersQuickView;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('quick view affiche un don pour le tiers ouvert', function (): void {
    $tiers = Tiers::factory()->create([
        'adresse_ligne1' => '1 rue de Test',
        'code_postal' => '69000',
        'ville' => 'Lyon',
    ]);
    $sousCat = SousCategorie::factory()->create(['nom' => 'Don courant']);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 80,
    ]);

    Livewire::test(TiersQuickView::class)
        ->call('loadTiers', $tiers->id)
        ->assertSee('Don courant');
});
