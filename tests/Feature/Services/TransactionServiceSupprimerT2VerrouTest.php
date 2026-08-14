<?php

declare(strict_types=1);

/**
 * Anomalie 2 (audit) — TransactionService::supprimerT2SiExiste() force-delete
 * la T2 (règlement/encaissement séparé) d'une T1 sans vérifier aucun verrou
 * propre à T2. delete()/annuler() contrôlent bien les verrous de T1
 * (rapprochement, remise, facture, immobilisation) mais pas ceux de T2 : une
 * T2 déjà pointée dans un rapprochement bancaire, ou intégrée à une remise,
 * pouvait donc être effacée alors que le rapprochement/la remise, eux,
 * survivaient — laissant un solde de rapprochement ou une remise incohérents.
 *
 * Défaut préexistant, indépendant des immobilisations : atteignable pour
 * toute dépense (ou recette) réglée dont on supprime/annule la dette/créance.
 */

use App\Enums\ModePaiement;
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Comptes système : 401, 411, 5112
    SystemeSeeder::seed();

    // CompteBancaire + Compte 512X correspondant (via IBAN)
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
    ]);
    BancairesSeeder::seed();

    // Compte 606 (classe 6) pour la ventilation de la dépense
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Achats non stockés de matières et fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(TransactionService::class);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Dépense comptant live : produit T1 (dette 401, journal Achat) + T2
 * (règlement 512X, journal Banque) séparées, lettrées sur le 401 — même
 * mécanique que DepenseComptantT2SepareeTest.
 */
function depenseRegleeAvecT2(object $ctx, float $montant = 200.0): Transaction
{
    $t1 = $ctx->service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2026-01-10',
        'libelle' => 'Achat fournitures',
        'montant_total' => (string) $montant,
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiers->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [[
        'compte_id' => $ctx->compte606->id,
        'montant' => (string) $montant,
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    return $t1->fresh();
}

// ---------------------------------------------------------------------------
// Rapprochement
// ---------------------------------------------------------------------------

it('refuse la suppression d’une dépense réglée dont le règlement (T2) est pointé dans un rapprochement', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);
    expect($t2)->not->toBeNull();

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::EnCours,
    ]);
    $t2->update(['rapprochement_id' => $rapprochement->id]);

    expect(fn () => $this->service->delete($t1->fresh()))
        ->toThrow(RuntimeException::class, 'rapprochement');

    // Rien n'est supprimé : ni T1, ni T2, ni le rapprochement.
    expect(Transaction::find($t1->id))->not->toBeNull();
    expect(Transaction::find($t2->id))->not->toBeNull();
    expect(TransactionLigne::where('transaction_id', $t2->id)->count())->toBeGreaterThan(0);
    expect(RapprochementBancaire::find($rapprochement->id))->not->toBeNull();
});

it('refuse l’annulation d’une dépense réglée dont le règlement (T2) est pointé dans un rapprochement', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);

    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'statut' => StatutRapprochement::EnCours,
    ]);
    $t2->update(['rapprochement_id' => $rapprochement->id]);

    expect(fn () => $this->service->annuler($t1->fresh(), 'test'))
        ->toThrow(RuntimeException::class, 'rapprochement');

    expect($t1->fresh()->deleted_at)->toBeNull();
    expect(Transaction::find($t2->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Remise bancaire
// ---------------------------------------------------------------------------

it('refuse la suppression d’une dépense réglée dont le règlement (T2) fait partie d’une remise bancaire', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);
    expect($t2)->not->toBeNull();

    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2026-01-15',
        'mode_paiement' => ModePaiement::Virement->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise test',
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['remise_id' => $remise->id]);

    expect(fn () => $this->service->delete($t1->fresh()))
        ->toThrow(RuntimeException::class, 'remise');

    expect(Transaction::find($t1->id))->not->toBeNull();
    expect(Transaction::find($t2->id))->not->toBeNull();
    expect(RemiseBancaire::find($remise->id))->not->toBeNull();
});

it('refuse l’annulation d’une dépense réglée dont le règlement (T2) fait partie d’une remise bancaire', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);

    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2026-01-15',
        'mode_paiement' => ModePaiement::Virement->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise test',
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['remise_id' => $remise->id]);

    expect(fn () => $this->service->annuler($t1->fresh(), 'test'))
        ->toThrow(RuntimeException::class, 'remise');

    expect($t1->fresh()->deleted_at)->toBeNull();
    expect(Transaction::find($t2->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas nominal — ni rapproché ni remisé : le parcours normal doit continuer
// de fonctionner (fréquent, ne doit jamais casser).
// ---------------------------------------------------------------------------

it('supprime la T1 et la T2 quand le règlement d’une dépense n’est ni pointé ni remisé (cas nominal)', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);
    expect($t2)->not->toBeNull();

    $this->service->delete($t1->fresh());

    expect(Transaction::find($t1->id))->toBeNull();
    expect(Transaction::withTrashed()->find($t1->id))->not->toBeNull('T1 doit être soft-deleted');
    expect(Transaction::find($t2->id))->toBeNull();
    expect(Transaction::withTrashed()->find($t2->id))->toBeNull('T2 doit être force-deleted (pas d’orphelin)');
});

it('annule (soft-delete) la T1 et force-delete la T2 quand le règlement n’est ni pointé ni remisé (cas nominal)', function () {
    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);
    expect($t2)->not->toBeNull();

    $this->service->annuler($t1->fresh(), 'test');

    expect($t1->fresh()->deleted_at)->not->toBeNull();
    expect(Transaction::withTrashed()->find($t2->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Verrou pessimiste sur la T2
// ---------------------------------------------------------------------------

/**
 * Contre-audit P1-02 — la garde ci-dessus lisait l'état de la T2 (rapprochement,
 * remise, facture) sans verrou : un pointage ou une mise en remise concurrents
 * pouvaient s'intercaler entre le contrôle et le forceDelete, et la T2 partait
 * quand même. Le contrôle ne vaut que si la ligne est verrouillée pendant qu'on
 * la lit et jusqu'au commit.
 *
 * Test réservé à MySQL : SQLiteGrammar::compileLock() retourne une chaîne vide,
 * le verrou y est un no-op inobservable. C'est la suite `phpunit.mysql.xml` qui
 * fait foi ici (`pest -c phpunit.mysql.xml`).
 */
it('verrouille la T2 avant de contrôler ses rattachements', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite compile les verrous en no-op : contrôle non observable.');
    }

    $t1 = depenseRegleeAvecT2($this);
    $t2 = app(ReglementOperationService::class)->trouverT2($t1);
    expect($t2)->not->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->service->delete($t1->fresh());
    $requetes = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $selectVerrouilleSurT2 = $requetes->first(
        fn (string $sql): bool => str_contains($sql, 'from `transactions`')
            && str_contains($sql, 'for update')
    );

    expect($selectVerrouilleSurT2)->not->toBeNull(
        'Aucun `select ... from `transactions` ... for update` : la T2 est lue sans verrou.'
    );
});
