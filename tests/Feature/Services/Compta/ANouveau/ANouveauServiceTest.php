<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreview;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    SystemeSeeder::seed();

    $this->acteurAN = User::factory()->create();
    $this->acteurAN->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->exerciceAN = Exercice::create([
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
});

function compteServiceAN(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

/** @return array{TransactionLigne, TransactionLigne} */
function ecritureServiceAN(Compte $debit, Compte $credit, string $montant, ?int $tiersCredit = null): array
{
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => '2026-08-31',
        'libelle' => 'Source AN',
        'montant_total' => $montant,
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
    ]);

    $ligneDebit = TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $debit->id,
        'debit' => $montant,
        'credit' => '0.00',
        'montant' => $montant,
    ]);
    $ligneCredit = TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $credit->id,
        'debit' => '0.00',
        'credit' => $montant,
        'montant' => $montant,
        'tiers_id' => $tiersCredit,
    ]);

    return [$ligneDebit, $ligneCredit];
}

it('persiste une piece AN unique equilibree avec sa filiation auxiliaire', function (): void {
    $charge = compteServiceAN('606-S', 6);
    $fournisseur = Tiers::factory()->create();
    [, $source401] = ecritureServiceAN(
        $charge,
        Compte::ofNumeroSysteme('401'),
        '75.25',
        (int) $fournisseur->id,
    );

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $service = app(ANouveauService::class);
    $generation = $service->persister($preview, OrigineANouveau::Cloture, $this->acteurAN);
    $memeGeneration = $service->persister($preview, OrigineANouveau::Cloture, $this->acteurAN);
    $transaction = $generation->transaction()->with('lignes')->firstOrFail();

    expect($memeGeneration->is($generation))->toBeTrue()
        ->and(ANouveauGeneration::where('exercice_cible', 2026)->count())->toBe(1)
        ->and($transaction->date->toDateString())->toBe('2026-09-01')
        ->and($transaction->journal)->toBe(JournalComptable::AN)
        ->and($transaction->type_ecriture)->toBe('an')
        ->and($transaction->type)->toBe(TypeTransaction::AN)
        ->and($transaction->equilibree)->toBeTrue()
        ->and($transaction->lignes)->toHaveCount(2)
        ->and($transaction->lignes->every(
            fn (TransactionLigne $ligne): bool => $ligne->operation_id === null && $ligne->seance === null
        ))->toBeTrue();

    $origine = $generation->origines()->firstOrFail();
    expect($origine->ligne_source_id)->toBe((int) $source401->id)
        ->and($origine->ligne_racine_id)->toBe((int) $source401->id)
        ->and($origine->ligneAN->tiers_id)->toBe((int) $fournisseur->id)
        ->and($origine->ligneAN->lettrage_code)->toBeNull();
});

it('annule toute persistance si l apercu est desequilibre', function (): void {
    $compte = compteServiceAN('512-X', 5);
    $preview = new ANouveauPreview(
        exerciceSource: 2025,
        exerciceCible: 2026,
        dateCible: new CarbonImmutable('2026-09-01'),
        lignes: [[
            'compte_id' => (int) $compte->id,
            'numero_pcg' => '512-X',
            'debit' => '10.00',
            'credit' => '0.00',
            'tiers_id' => null,
            'libelle' => 'Aperçu invalide',
            'source_ligne_id' => null,
            'racine_ligne_id' => null,
        ]],
        totalDebit: '10.00',
        totalCredit: '0.00',
    );

    expect(fn () => app(ANouveauService::class)->persister(
        $preview,
        OrigineANouveau::Cloture,
        $this->acteurAN,
    ))->toThrow(ANouveauInvalideException::class, 'déséquilibré');

    expect(ANouveauGeneration::count())->toBe(0)
        ->and(Transaction::where('journal', JournalComptable::AN)->count())->toBe(0);
});

it('invalide la generation et soft-delete la piece et ses lignes', function (): void {
    $charge = compteServiceAN('606-I', 6);
    $fournisseur = Tiers::factory()->create();
    ecritureServiceAN(
        $charge,
        Compte::ofNumeroSysteme('401'),
        '20.00',
        (int) $fournisseur->id,
    );

    $preview = app(ANouveauPreviewBuilder::class)->build(2025);
    $service = app(ANouveauService::class);
    $generation = $service->persister($preview, OrigineANouveau::Cloture, $this->acteurAN);
    $transactionId = (int) $generation->transaction_id;
    $ligneIds = $generation->transaction->lignes()->pluck('id')->map(fn ($id): int => (int) $id)->all();

    $service->invalider($this->exerciceAN, $this->acteurAN, 'Réouverture exceptionnelle');

    $generation->refresh();
    $lignesSupprimees = TransactionLigne::withTrashed()->whereIn('id', $ligneIds)->get();
    expect($generation->statut)->toBe(StatutANouveau::Invalidee)
        ->and($generation->invalidee_par_id)->toBe((int) $this->acteurAN->id)
        ->and($generation->invalidee_at)->not->toBeNull()
        ->and($generation->motif_invalidation)->toBe('Réouverture exceptionnelle')
        ->and(Transaction::withTrashed()->findOrFail($transactionId)->trashed())->toBeTrue()
        ->and($lignesSupprimees->every(fn (TransactionLigne $ligne): bool => $ligne->trashed()))->toBeTrue()
        ->and($generation->origines()->count())->toBe(1);

    $nouvelleGeneration = $service->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $this->acteurAN,
    );

    expect($nouvelleGeneration->is($generation))->toBeFalse()
        ->and($nouvelleGeneration->transaction_id)->not->toBe($transactionId)
        ->and(ANouveauGeneration::where('statut', StatutANouveau::Active)->count())->toBe(1)
        ->and(ANouveauGeneration::where('statut', StatutANouveau::Invalidee)->count())->toBe(1);
});
