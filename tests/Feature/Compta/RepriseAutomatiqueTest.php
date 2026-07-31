<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Enums\StatutExercice;
use App\Models\ANouveauGeneration;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ClotureCheckService;
use App\Services\Compta\ANouveau\RepriseAutomatiqueService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Saisir un solde d'ouverture est un geste de gestion ordinaire. Le traduire en
 * écriture exigeait une commande d'administration système réclamant
 * l'association, l'acteur et l'exercice — trois valeurs que l'application connaît
 * déjà. Ces tests fixent la frontière : la reprise se fait seule quand il n'y a
 * rien à arbitrer, et s'abstient sans erreur dès qu'une décision humaine est
 * nécessaire.
 */

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
});

it('reprend les soldes d’ouverture quand le calcul est net', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    $generation = app(RepriseAutomatiqueService::class)->tenter($this->user);

    expect($generation)->not->toBeNull()
        ->and($generation->origine)->toBe(OrigineANouveau::RepriseInitiale);

    // Le montant est réellement dans le grand livre, pas seulement à l'écran.
    $ligne = TransactionLigne::where('transaction_id', (int) $generation->transaction_id)
        ->where('compte_id', (int) $this->compte512X->id)
        ->sole();

    expect((float) $ligne->debit)->toBe(2388.82);
});

it('ne fait rien quand l’association démarre à zéro', function (): void {
    // Cas nominal d'une association qui n'a pas d'existant : rien à reprendre.
    expect(app(RepriseAutomatiqueService::class)->tenter($this->user))->toBeNull()
        ->and(ANouveauGeneration::count())->toBe(0);
});

it('ne fait rien quand une reprise existe déjà', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    app(RepriseAutomatiqueService::class)->tenter($this->user);

    // Un second appel ne doit pas créer de doublon : un compte ajouté après la
    // reprise relève d'une décision humaine, pas d'un rattrapage silencieux.
    expect(app(RepriseAutomatiqueService::class)->tenter($this->user))->toBeNull()
        ->and(ANouveauGeneration::count())->toBe(1);
});

it('ne fait rien quand l’exercice ne peut pas être déduit', function (): void {
    // Solde daté hors de tout exercice connu.
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2019-08-31',
    ]);

    expect(app(RepriseAutomatiqueService::class)->tenter($this->user))->toBeNull()
        ->and(ANouveauGeneration::count())->toBe(0);
});

it('ne fait rien et ne lève pas quand un arbitrage humain est requis', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    // Un mouvement daté du jour même de la date de référence : seule une décision
    // humaine dit s'il est déjà compris dans le solde ou non.
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'date' => '2024-08-31',
        'equilibree' => true,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'compte_id' => (int) $this->compte512X->id,
        'montant' => 0,
        'debit' => '50.00',
        'credit' => 0,
    ]);

    expect(app(RepriseAutomatiqueService::class)->tenter($this->user))->toBeNull()
        ->and(ANouveauGeneration::count())->toBe(0);
});

it('ne fait rien sans acteur', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    expect(app(RepriseAutomatiqueService::class)->tenter(null))->toBeNull()
        ->and(ANouveauGeneration::count())->toBe(0);
});

it('débloque la garde de clôture', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    $garde = fn (): object => collect(app(ClotureCheckService::class)->executer(2024)->bloquants)
        ->firstWhere('nom', 'Préalables comptables');

    expect($garde()->ok)->toBeFalse();

    app(RepriseAutomatiqueService::class)->tenter($this->user);

    expect($garde()->ok)->toBeTrue();
});

it('ignore une reprise invalidée par une réouverture', function (): void {
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    ANouveauGeneration::create([
        'association_id' => $this->association->id,
        'exercice_source' => 2023,
        'exercice_cible' => 2024,
        'transaction_id' => null,
        'origine' => OrigineANouveau::RepriseInitiale,
        'statut' => StatutANouveau::Invalidee,
        'cree_par_id' => $this->user->id,
    ]);

    // Une reprise invalidée ne compte pas : la reprise doit se refaire.
    expect(app(RepriseAutomatiqueService::class)->tenter($this->user))->not->toBeNull();
});
