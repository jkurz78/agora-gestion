<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Livewire\OperationDetail;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

/**
 * Le rattachement de l'exercice 2025 (sept. 2025 → août 2026) d'une ligne d'origine.
 * Aucun libellé : il est hérité de la ligne d'origine.
 */
function provisionPourLigne(TransactionLigne $ligne, int $exercice = 2025): Provision
{
    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => $exercice,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);
}

/**
 * Les lignes d'une écriture, indexées par numéro de compte.
 *
 * Le compte d'origine est fabriqué par la factory, avec un numéro unique : on
 * le désigne par `$ligne->compte->numero_pcg`, jamais par un numéro écrit en dur.
 *
 * @return Collection<string, TransactionLigne>
 */
function lignesParCompte(Transaction $ecriture): Collection
{
    return $ecriture->lignes->keyBy(fn (TransactionLigne $l) => $l->compte->numero_pcg);
}

/**
 * Impact d'une écriture sur le RÉSULTAT, signé : positif quand elle l'augmente.
 *
 * Seules les classes 6 et 7 comptent — c'est tout ce que lit le compte de
 * résultat. Un produit (classe 7) augmente le résultat au crédit, une charge
 * (classe 6) le diminue au débit. Un compte de classe 4 ne pèse rien : c'est
 * l'erreur de la spec de juin 2026, qui débitait 487 en croyant retirer un produit.
 */
function impactSurLeResultat(Transaction $ecriture): float
{
    $impact = 0.0;

    foreach ($ecriture->lignes as $ligne) {
        $classe = (int) $ligne->compte->classe;

        if ($classe === 7) {
            $impact += (float) $ligne->credit - (float) $ligne->debit;
        } elseif ($classe === 6) {
            $impact -= (float) $ligne->debit - (float) $ligne->credit;
        }
    }

    return round($impact, 2);
}

// ── Dotation : dernier jour de N ─────────────────────────────────────────────

test('PCA : dotation 7xx D / 487 C au dernier jour de N, opération portée par la seule ligne 7xx', function () {
    $ligne = $this->ligneOrigine(7, 175.0, operationId: null);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);

    expect($dotation->date->format('Y-m-d'))->toBe('2026-08-31');
    expect($dotation->journal)->toBe(JournalComptable::Od);
    expect($dotation->type_ecriture)->toBe('normale');
    expect((int) $dotation->provision_id)->toBe((int) $provision->id);
    expect($dotation->lignes)->toHaveCount(2);

    $lignes = lignesParCompte($dotation);

    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(175.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(0.0);
    expect((float) $lignes['487']->credit)->toBe(175.0);
    expect((float) $lignes['487']->debit)->toBe(0.0);
});

test('CCA : dotation 486 D / 6xx C', function () {
    $ligne = $this->ligneOrigine(6, 90.0);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);
    $lignes = lignesParCompte($dotation);

    expect($dotation->lignes)->toHaveCount(2);
    expect((float) $lignes['486']->debit)->toBe(90.0);
    expect((float) $lignes['486']->credit)->toBe(0.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(90.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(0.0);
});

// ── Extourne : premier jour de N+1 ───────────────────────────────────────────

test('PCA : l’extourne est le miroir exact, 487 D / 7xx C, au premier jour de N+1', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $extourne = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
    expect($extourne->type_ecriture)->toBe('extourne');
    expect($extourne->journal)->toBe(JournalComptable::Od);
    expect((int) $extourne->provision_id)->toBe((int) $provision->id);

    $lignes = lignesParCompte($extourne);
    expect((float) $lignes['487']->debit)->toBe(175.0);
    expect((float) $lignes['487']->credit)->toBe(0.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(175.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(0.0);
});

test('CCA : l’extourne est le miroir exact, 6xx D / 486 C, au premier jour de N+1', function () {
    $ligne = $this->ligneOrigine(6, 90.0);
    $provision = provisionPourLigne($ligne);

    $extourne = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');

    $lignes = lignesParCompte($extourne);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(90.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(0.0);
    expect((float) $lignes['486']->credit)->toBe(90.0);
    expect((float) $lignes['486']->debit)->toBe(0.0);
});

// ── Le sens de l'impact sur le résultat ──────────────────────────────────────
//
// L'erreur de la spec de juin 2026 a survécu dix-huit mois parce qu'aucun test
// n'affirmait CE QUE L'ÉCRITURE FAIT AU RÉSULTAT, seulement quels comptes elle
// touchait. Ces tests le disent en euros, par cas, dotation puis extourne.

test('l’impact sur le résultat : la dotation corrige N, l’extourne rend à N+1, et la somme est nulle', function (int $classe, float $montant, float $impactDotation) {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);
    $dotation = $generateur->pourProvisionDotation($provision);
    $extourne = $generateur->pourProvisionExtourne($provision);

    // PCA (produit de N rattaché à N+1) : le résultat de N BAISSE du montant.
    // CCA (charge de N rattachée à N+1) : le résultat de N MONTE du montant.
    expect(impactSurLeResultat($dotation))->toBe($impactDotation);
    expect(impactSurLeResultat($extourne))->toBe(-$impactDotation);
    expect(impactSurLeResultat($dotation) + impactSurLeResultat($extourne))->toBe(0.0);
})->with([
    'PCA : un produit de 175 € retiré de N' => [7, 175.0, -175.0],
    'CCA : une charge de 90 € retirée de N' => [6, 90.0, 90.0],
]);

test('le compte de résultat n’est touché que par la ligne de l’origine ; la contrepartie est de classe 4', function (int $classe, string $contrepartie) {
    $ligne = $this->ligneOrigine($classe, 120.0);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        $classes = $ecriture->lignes->map(fn (TransactionLigne $l) => (int) $l->compte->classe)->sort()->values()->all();

        expect($classes)->toBe([4, $classe]);

        $comptes = $ecriture->lignes->map(fn (TransactionLigne $l) => (string) $l->compte->numero_pcg)->sort()->values()->all();

        expect($comptes)->toBe(collect([$contrepartie, (string) $ligne->compte->numero_pcg])->sort()->values()->all());
    }
})->with([
    'PCA' => [7, '487'],
    'CCA' => [6, '486'],
]);

// ── D8 : la dimension analytique ─────────────────────────────────────────────

test('l’opération et la séance sont portées par la ligne 6/7 et PAS par la contrepartie de classe 4 (D8)', function (int $classe, string $contrepartie) {
    $operation = Operation::factory()->create([
        'association_id' => TenantContext::currentId(),
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);
    $ligne = $this->ligneOrigine($classe, 175.0, operationId: (int) $operation->id, seance: 3);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        $lignes = lignesParCompte($ecriture);
        $ligneResultat = $lignes[$ligne->compte->numero_pcg];

        expect((int) $ligneResultat->operation_id)->toBe((int) $operation->id);
        expect((int) $ligneResultat->seance)->toBe(3);
        expect($lignes[$contrepartie]->operation_id)->toBeNull();
        expect($lignes[$contrepartie]->seance)->toBeNull();
    }
})->with([
    'PCA' => [7, '487'],
    'CCA' => [6, '486'],
]);

test('une origine sans opération ni séance donne des lignes sans opération ni séance', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);

    foreach ($dotation->lignes as $l) {
        expect($l->operation_id)->toBeNull();
        expect($l->seance)->toBeNull();
    }
});

// ── Un consommateur de `montant` : l'écran de l'opération ────────────────────
//
// OperationDetail somme `transaction_lignes.montant` par type de transaction. Les
// lignes de rattachement portent donc la magnitude, pas 0 : à 0, le solde de
// l'opération serait faux (350 € au lieu de 175 € pour une PCA).

test('l’écran de l’opération garde un solde juste une fois le rattachement écrit', function (int $classe, float $montant, float $soldeAttendu) {
    $operation = Operation::factory()->create([
        'association_id' => TenantContext::currentId(),
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);
    $ligne = $this->ligneOrigine($classe, $montant, operationId: (int) $operation->id);

    app(ProvisionPDService::class)->generer(provisionPourLigne($ligne));

    Livewire::test(OperationDetail::class, ['operation' => $operation])
        ->assertViewHas('solde', $soldeAttendu);
})->with([
    'PCA : 175 € encaissés, le solde reste 175 €' => [7, 175.0, 175.0],
    'CCA : 90 € payés, le solde reste −90 €' => [6, 90.0, -90.0],
]);

// ── Gardes ───────────────────────────────────────────────────────────────────

test('le libellé de l’écriture nomme le cas et reprend le libellé de l’origine', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    expect($generateur->pourProvisionDotation($provision)->libelle)->toBe('Rattachement PCA : Ligne d’origine');
    expect($generateur->pourProvisionExtourne($provision)->libelle)->toBe('Extourne PCA : Ligne d’origine');
});

test('un montant nul ou négatif est refusé par le générateur lui-même', function (float $montant) {
    $ligne = $this->ligneOrigine(7, 175.0);

    // Non enregistré : le hook saving du modèle refuserait déjà ce montant. Le
    // générateur ne doit pas compter sur lui.
    $provision = (new Provision)->forceFill([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => $montant,
    ]);

    $generateur = app(EcritureGenerator::class);

    expect(fn () => $generateur->pourProvisionDotation($provision))->toThrow(RuntimeException::class, 'strictement positif');
    expect(fn () => $generateur->pourProvisionExtourne($provision))->toThrow(RuntimeException::class, 'strictement positif');
    expect(Transaction::where('journal', JournalComptable::Od->value)->count())->toBe(0);
})->with([
    'nul' => [0.0],
    'négatif' => [-175.0],
]);

test('un compte de résultat qui n’est pas celui de l’association courante est refusé', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    // On change d'association sans recharger le rattachement : ses relations ne
    // sont pas encore chargées, elles se résolvent sous le scope de l'autre.
    TenantContext::clear();
    TenantContext::boot(Association::factory()->create());

    expect(fn () => app(EcritureGenerator::class)->pourProvisionDotation($provision))
        ->toThrow(RuntimeException::class, 'introuvable dans cette association');

    expect(Transaction::query()->count())->toBe(0);
});

// ── ProvisionPDService : la paire d'écritures ────────────────────────────────

test('aucune écriture de rattachement ne porte de débit ou de crédit négatif (AC-15)', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    app(ProvisionPDService::class)->generer($provision);

    $lignes = TransactionLigne::whereIn(
        'transaction_id',
        Transaction::where('provision_id', $provision->id)->pluck('id')
    )->get();

    expect($lignes)->toHaveCount(4);
    foreach ($lignes as $l) {
        expect((float) $l->debit)->toBeGreaterThanOrEqual(0.0);
        expect((float) $l->credit)->toBeGreaterThanOrEqual(0.0);
    }
});

test('generer produit une dotation et une extourne équilibrées', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    app(ProvisionPDService::class)->generer($provision);

    $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();

    expect($ecritures->pluck('type_ecriture')->all())->toBe(['normale', 'extourne']);
    expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())->toBe(['2026-08-31', '2026-09-01']);
    expect($ecritures->pluck('journal')->unique()->all())->toBe([JournalComptable::Od]);
    expect($ecritures->every(fn (Transaction $t) => $t->equilibree === true))->toBeTrue();
});

test('generer régénère intégralement : un second appel remplace les deux écritures', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    $premieres = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    $service->generer($provision);
    $secondes = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    expect($secondes)->toHaveCount(2);
    expect(array_intersect($premieres, $secondes))->toBe([]);
    expect(TransactionLigne::whereIn('transaction_id', $secondes)->count())->toBe(4);
});

test('supprimer retire les deux écritures et leurs lignes', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    $ids = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    $service->supprimer($provision);

    expect(Transaction::withTrashed()->where('provision_id', $provision->id)->count())->toBe(0);
    expect(TransactionLigne::withTrashed()->whereIn('transaction_id', $ids)->count())->toBe(0);
});
