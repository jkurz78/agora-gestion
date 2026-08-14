<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Complète le formulaire de création d'immobilisation avec le choix
 * « à crédit » / « réglé immédiatement » (docs/specs/2026-08-04-immobilisations-design.md §7.3).
 *
 * ImmobilisationService::acquerir() sait déjà router vers pourReglementFournisseur
 * (voir tests/Feature/Immobilisation/AcquisitionTest.php et
 * tests/Unit/Services/Compta/EcritureGeneratorPourReglementFournisseurTest.php) — ce
 * qui manque, c'est la saisie côté ImmobilisationIndex.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    // SystemeSeeder (pas seulement un 401 manuel) : le règlement fournisseur
    // a besoin de 401 ET 530 (portage espèces) ET 5112 (placeholder résolu par
    // CompteTresorerieResolver).
    SystemeSeeder::seed();
    ImmobilisationComptesSeeder::seed();
});

it('crée une acquisition à crédit par défaut, dette 401 ouverte', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', '20 tenues d’escrime')
        ->set('quantite', 20)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '3000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect(Transaction::count())->toBe(1);

    $compte401 = Compte::ofNumeroSysteme('401');
    $ligne401 = TransactionLigne::where('transaction_id', $immo->transaction_id)
        ->where('compte_id', $compte401->id)
        ->firstOrFail();

    expect($ligne401->lettrage_code)->toBeNull('la dette doit rester ouverte, non lettrée');
});

it('règle immédiatement par virement : produit la transaction de règlement et le lettrage', function (): void {
    $tiers = Tiers::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Vidéoprojecteur')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '450.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_reglement_id', (int) $compteBancaire->id)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect(Transaction::count())->toBe(2);

    $compte401 = Compte::ofNumeroSysteme('401');
    $lignes401 = TransactionLigne::where('compte_id', $compte401->id)->get();

    expect($lignes401)->toHaveCount(2)
        ->and($lignes401->pluck('lettrage_code')->unique())->toHaveCount(1)
        ->and($lignes401->first()->lettrage_code)->not->toBeNull();

    $compte512 = Compte::where('compte_bancaire_id', (int) $compteBancaire->id)->sole();
    $lignePortage = TransactionLigne::where('compte_id', $compte512->id)->first();
    expect($lignePortage)->not->toBeNull()
        ->and((float) $lignePortage->credit)->toBe(450.0);
});

it('règle immédiatement en espèces sans compte bancaire requis', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Petit matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '80.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 36)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'especes')
        ->call('enregistrer')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(2);

    $compte530 = Compte::ofNumeroSysteme('530');
    $lignePortage = TransactionLigne::where('compte_id', $compte530->id)->first();
    expect($lignePortage)->not->toBeNull()
        ->and((float) $lignePortage->credit)->toBe(80.0);
});

it('refuse un règlement immédiat par virement sans compte bancaire fourni', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '100.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 36)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'virement')
        ->call('enregistrer')
        ->assertHasErrors('compte_reglement_id');

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

/**
 * Recette du 13/08/2026 — une acquisition réglée immédiatement restait au
 * statut « Dû ». L'écran de la transaction affichait alors deux informations
 * contradictoires : le bloc règlement, lu sur le grand livre, montrait bien le
 * paiement ; « Paiement effectué ? », lu sur `statut_reglement`, répondait non.
 *
 * Cause : acquerir() était le seul chemin de création à ne jamais appeler
 * EtatReglementResolver::syncer(). TransactionService::create() et
 * ReglementOperationService le font tous, ce qui est précisément ce qui aligne
 * la colonne dérivée sur les écritures réellement produites.
 */
it('marque la transaction comme réglée quand l’acquisition est payée immédiatement', function (): void {
    $tiers = Tiers::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Bros machin')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2183')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '19000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_reglement_id', (string) $compteBancaire->id)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();

    expect($immo->transaction->statut_reglement)
        ->not->toBe(StatutReglement::EnAttente, 'Une acquisition payée ne doit pas rester « Dû ».');
});

it('laisse la transaction due quand l’acquisition est à crédit', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Bros machin')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2183')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '19000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    expect(Immobilisation::firstOrFail()->transaction->statut_reglement)
        ->toBe(StatutReglement::EnAttente);
});

/**
 * Recette du 13/08/2026 — le formulaire demandait le mode et le compte, mais
 * pas la date : le décaissement était forcément daté du jour de l'achat. Une
 * facture réglée quelques jours plus tard produisait donc un mouvement bancaire
 * daté du mauvais jour, introuvable au rapprochement.
 */
it('date le règlement au jour saisi, pas au jour de l’achat', function (): void {
    $tiers = Tiers::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Bros machin')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2183')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '19000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_reglement_id', (string) $compteBancaire->id)
        ->set('date_reglement', '2026-10-15')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    $t2 = app(ReglementOperationService::class)->trouverT2($immo->transaction);

    expect($t2)->not->toBeNull()
        ->and($t2->date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($immo->transaction->date->format('Y-m-d'))->toBe('2026-09-12');
});

it('refuse un règlement antérieur à l’achat', function (): void {
    $tiers = Tiers::factory()->create();
    $compteBancaire = CompteBancaire::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Bros machin')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2183')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '19000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->set('regleImmediatement', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_reglement_id', (string) $compteBancaire->id)
        ->set('date_reglement', '2026-08-01')
        ->call('enregistrer')
        ->assertHasErrors('date_reglement');

    expect(Immobilisation::count())->toBe(0);
});

it('propose par défaut la date d’achat comme date de règlement', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('date_achat', '2026-11-03')
        ->assertSet('date_reglement', '2026-11-03');
});
