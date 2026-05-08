<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('n\'affiche pas l\'onglet Dons si le tiers n\'a aucun don', function (): void {
    $tiers = Tiers::factory()->create();

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers));

    $response->assertOk();
    // L'onglet "Coordonnées" est toujours là
    $response->assertSee('Coordonnées');
    // Mais "Dons" en tant qu'onglet n'apparaît pas
    expect($response->getContent())->not->toContain('?onglet=dons');
});

it('affiche l\'onglet Dons avec compteur si le tiers a des dons', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    foreach (['2025-03-10', '2025-04-12', '2025-06-01'] as $d) {
        $tx = Transaction::factory()->create([
            'tiers_id' => $tiers->id,
            'date' => $d,
            'type' => TypeTransaction::Recette->value,
            'statut_reglement' => StatutReglement::Recu->value,
        ]);
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => $sousCat->id,
            'montant' => 50,
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers));

    $response->assertOk()
        ->assertSee('Dons')
        ->assertSee('(3)');
});
