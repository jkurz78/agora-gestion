<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Recette — « Voir l'écriture d'acquisition » ouvrait le formulaire générique
 * de saisie de transaction. Trois symptômes en découlaient :
 *
 *  - aucune ligne de dépense affichée et un montant total à 0,00 €, parce que
 *    TransactionForm charge les lignes via TransactionLigne::scopeVentilation(),
 *    qui filtre `classe IN (6,7)` — or une acquisition se ventile sur un compte
 *    de CLASSE 2, systématiquement écarté ;
 *  - un compte bancaire vide, qui n'est pas une anomalie : la T1 porte la dette
 *    401, c'est la T2 qui porte la banque. Le champ n'avait simplement rien à
 *    faire là ;
 *  - un bloc « paiement » proposant mode et date mais sans compte ni montant,
 *    et un bouton « Mettre à jour » grisé — puisque la transaction est pilotée
 *    par la fiche et refusée à l'enregistrement.
 *
 * La fiche affiche donc désormais l'écriture telle qu'elle est, en lecture
 * seule : les lignes réelles du grand livre, débit et crédit. Le formulaire de
 * saisie reste réservé à ce qui se saisit.
 *
 * Décision assumée et provisoire (recette du 13/08/2026) : la solution pérenne
 * est un écran de consultation de transaction qui permette aussi de la traiter.
 * Il n'existe pas encore — voir le ticket dédié.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->fournisseur = Tiers::factory()->create([
        'type' => 'entreprise',
        'entreprise' => 'Decathlon',
    ]);

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: $this->fournisseur,
        libelle: 'Vidéoprojecteur',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('affiche les deux lignes réelles de l’écriture d’acquisition', function (): void {
    $composant = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('voirEcriture', (int) $this->immo->transaction_id);

    // Le compte de classe 2 au débit — celui que le formulaire escamotait.
    $composant->assertSee('2188')
        // …et sa contrepartie, la dette fournisseur au crédit.
        ->assertSee('401')
        ->assertSee('Decathlon');
});

it('affiche le montant réel de l’écriture, pas 0,00 €', function (): void {
    $composant = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('voirEcriture', (int) $this->immo->transaction_id);

    expect($composant->html())->toContain('3 000,00');
});

it('n’ouvre plus le formulaire de saisie de transaction', function (): void {
    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('voirEcriture', (int) $this->immo->transaction_id)
        ->assertNotDispatched('edit-transaction');
});

it('refuse d’afficher une écriture qui n’appartient pas à la fiche', function (): void {
    $autre = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Autre bien',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '500.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo])
        ->call('voirEcriture', (int) $autre->transaction_id)
        ->assertForbidden();

    expect(Immobilisation::count())->toBe(2);
});
