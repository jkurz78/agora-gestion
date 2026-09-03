<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Livewire\Immobilisations\DotationsExercice;
use App\Livewire\TransactionUniverselle;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * C1 (audit) — le serveur refuse désormais delete()/annuler()/isExtournable()
 * sur toute transaction pilotée par une immobilisation
 * (Transaction::isLockedByImmobilisation(), cf. VerrouTransactionServiceTest),
 * mais la liste « Recettes & dépenses » proposait encore Supprimer et
 * Extourner dessus : l'utilisateur cliquait et recevait une erreur serveur.
 *
 * Les dates sont posées dans l'exercice courant (frozen « now » 2026-01-15,
 * donc exercice 2025 = 01/09/2025 → 31/08/2026, cf. tests/Pest.php) : la
 * liste filtre par défaut sur cet exercice.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('masque Supprimer et Extourner sur la transaction d’acquisition', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2025-09-12'),
        dateMiseEnService: Carbon::parse('2025-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
    $transactionId = (int) $immo->transaction_id;

    Livewire::test(TransactionUniverselle::class)
        // Témoin : la ligne est bien rendue (sinon les assertions négatives
        // ci-dessous seraient vraies par absence de ligne, pas par masquage).
        ->assertSeeHtml("openEdit('depense', {$transactionId})")
        ->assertDontSeeHtml("deleteRow('depense', {$transactionId})")
        ->assertDontSeeHtml("\$dispatch('extourne:open', { id: {$transactionId} })");
});

/**
 * Les dotations sont en journal OD, donc normalement absentes de cette liste
 * — vue de trésorerie, délibéré (cf. DotationVentilerTest). Ne pas lever ce
 * filtre : on force ici le journal de la transaction de dotation générée pour
 * la rendre visible dans le test, uniquement afin de prouver que le masquage
 * reconnaît une transaction de dotation aussi bien qu'une acquisition —
 * autrement ce chemin resterait du code mort, jamais exercé.
 */
it('masque Supprimer et Extourner sur une transaction de dotation', function (): void {
    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2025-09-12'),
        dateMiseEnService: Carbon::parse('2025-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2026-10-15');
    Livewire::test(DotationsExercice::class)->set('exercice', 2025)->call('genererTout');
    Carbon::setTestNow('2026-01-15 10:00:00');

    // DotationsExercice::mount() lit ExerciceService::current() pendant
    // l'excursion à Oct. 2026, avant que la dotation n'existe : ni l'exercice
    // 2025 ni le 2026 ne portent encore d'écriture de classe 6/7 à ce
    // moment-là, donc current() rend le calcul brut (2026) — et, depuis que
    // current() mémoïse sa première résolution en session (une écriture par
    // requête pour épargner les 51 sites d'appel, cf. ExerciceService), ce
    // 2026 survit au retour à « 2026-01-15 » ci-dessus. Sans purge, le
    // TransactionUniverselle instancié plus bas hériterait de ce 2026 périmé
    // et filtrerait hors champ la dotation qu'on vient de générer dans le
    // 2025. Une requête HTTP normale ne rejoue jamais un tel aller-retour
    // dans le temps ; c'est un artefact du test, pas un risque en production.
    session()->forget('exercice_actif');

    $dotation = ImmobilisationDotation::where('exercice', 2025)
        ->where('immobilisation_id', $immo->id)
        ->firstOrFail();
    $transactionId = (int) $dotation->transaction_id;

    Transaction::whereKey($transactionId)->update(['journal' => JournalComptable::Achat->value]);

    Livewire::test(TransactionUniverselle::class)
        // La dotation est datée au dernier jour de l'exercice, qui coïncide
        // avec la borne par défaut du filtre — SQLite compare les dates en
        // chaîne et exclut cette borne exacte (cf. feedback_sqlite_date_
        // boundary) : on élargit la fenêtre pour rester à l'intérieur.
        ->set('filterDateFin', '2026-09-30')
        // Témoin : la ligne est bien rendue (sinon les assertions négatives
        // ci-dessous seraient vraies par absence de ligne, pas par masquage).
        ->assertSeeHtml("openEdit('depense', {$transactionId})")
        ->assertDontSeeHtml("deleteRow('depense', {$transactionId})")
        ->assertDontSeeHtml("\$dispatch('extourne:open', { id: {$transactionId} })");
});

it('laisse Supprimer visible sur une transaction ordinaire en attente', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);
    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-01']);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 100,
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->assertSeeHtml("deleteRow('depense', {$tx->id})");
});

it('laisse Extourner visible sur une transaction ordinaire réglée', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '607', 'classe' => 6]);
    $tx = Transaction::factory()->asDepense()->create([
        'date' => '2025-10-01',
        'statut_reglement' => 'recu',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 100,
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->assertSeeHtml("\$dispatch('extourne:open', { id: {$tx->id} })");
});
