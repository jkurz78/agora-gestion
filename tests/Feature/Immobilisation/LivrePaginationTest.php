<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * B4 (audit) — ImmobilisationIndex::render() faisait un ->get() sur la
 * totalité des immobilisations du tenant : une association qui immobilise
 * régulièrement finirait par tout charger et tout rendre d'un coup. Ce
 * fichier verrouille la pagination introduite pour corriger ça : le
 * changement de page ne perd aucune fiche, et les totaux de pied de tableau
 * restent ceux de l'ENSEMBLE du registre — jamais seulement de la page
 * affichée (le piège explicitement signalé par l'audit).
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->compte = Compte::ofNumero('2188');
    $this->compteAmortissement = Compte::ofNumero('28188');

    // Numérotation explicite et séquentielle (IM00001..IM00025) : l'ordre de
    // tri du livre (orderBy('numero')) devient prévisible pour le test, sans
    // dépendre du tirage aléatoire du factory par défaut.
    $this->creerFiches = function (int $nombre, string $montantUnitaire = '100.00'): void {
        Immobilisation::factory()
            ->count($nombre)
            ->sequence(fn ($sequence) => [
                'numero' => 'IM'.str_pad((string) ($sequence->index + 1), 5, '0', STR_PAD_LEFT),
            ])
            ->create([
                'compte_id' => $this->compte->id,
                'compte_amortissement_id' => $this->compteAmortissement->id,
                'montant_acquisition' => $montantUnitaire,
            ]);
    };
});

it('la pagination répartit les fiches sans en perdre au changement de page', function (): void {
    ($this->creerFiches)(25);

    Livewire::test(ImmobilisationIndex::class)
        ->assertSee('IM00001')
        ->assertSee('IM00020')
        ->assertDontSee('IM00021')
        ->call('gotoPage', 2)
        ->assertSee('IM00021')
        ->assertSee('IM00025')
        ->assertDontSee('IM00001');
});

it('changer de taille de page revient à la première page', function (): void {
    ($this->creerFiches)(25);

    Livewire::test(ImmobilisationIndex::class)
        ->call('gotoPage', 2)
        ->assertSee('IM00021')
        ->set('perPage', 50)
        ->assertSet('paginators.page', 1)
        ->assertSee('IM00001')
        ->assertSee('IM00025');
});

it('les totaux de pied de tableau portent sur tout le registre, pas seulement la page affichée', function (): void {
    ($this->creerFiches)(25, '100.00');

    foreach (Immobilisation::all() as $immobilisation) {
        ImmobilisationDotation::create([
            'immobilisation_id' => $immobilisation->id,
            'exercice' => 2025,
            'montant' => '10.00',
            'transaction_id' => Transaction::factory()->create()->id,
        ]);
    }

    // Brut : 25 x 100,00 € = 2 500,00 € — Amorti : 25 x 10,00 € = 250,00 €
    // — Net : 2 250,00 €. Une somme limitée à la page courante (5 fiches sur
    // la page 2) donnerait 500,00 / 50,00 / 450,00 : la divergence est
    // évidente si le bug reparaît.
    Livewire::test(ImmobilisationIndex::class)
        ->assertSee('2 500,00')
        ->assertSee('250,00')
        ->assertSee('2 250,00')
        ->call('gotoPage', 2)
        ->assertSee('2 500,00')
        ->assertSee('250,00')
        ->assertSee('2 250,00');
});
