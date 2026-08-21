<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Enums\StatutReglement;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->reglements = app(PosteTiersReglementService::class);
    $this->postes = app(PostesTiersOuvertsService::class);
    $this->ecritures = app(EcritureGenerator::class);
});

/**
 * @return array{Transaction, TransactionLigne}
 */
function creerPosteAnnulableTask5(object $contexte, int $montantCentimes = 10000): array
{
    $tiers = Tiers::factory()->create(['association_id' => $contexte->association->id]);
    $transaction = $contexte->ecritures->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes($montantCentimes),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance à annuler',
    );
    $transaction->update(['statut_reglement' => StatutReglement::EnAttente]);

    /** @var TransactionLigne $ligne */
    $ligne = $transaction->lignes()->whereHas(
        'compte',
        fn ($query) => $query->where('numero_pcg', '411')
    )->firstOrFail();

    return [$transaction->fresh(), $ligne];
}

function reglerPosteTask5(object $contexte, TransactionLigne $ligne, int $montantCentimes): Transaction
{
    return $contexte->reglements->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne->id,
        montantCentimes: $montantCentimes,
        date: CarbonImmutable::parse('2026-07-23'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $contexte->compteBancaire->id,
        exercice: 2025,
    ));
}

function montantCentimesTask5(TransactionLigne $ligne): int
{
    return abs(
        MontantDecimal::versCentimes((string) $ligne->debit)
        - MontantDecimal::versCentimes((string) $ligne->credit)
    );
}

it('annule un règlement total, délettre la ligne tiers et rouvre le poste', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);

    $this->reglements->annuler((int) $t2->id);

    $poste = $this->postes->pourTransaction($t1->fresh(), 2025);

    expect(Transaction::withTrashed()->find($t2->id))->toBeNull()
        ->and($parent->fresh()->lettrage_code)->toBeNull()
        ->and(montantCentimesTask5($parent->fresh()))->toBe(10000)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente)
        ->and($poste?->soldeCentimes)->toBe(10000);
});

it('annule un règlement partiel et fusionne la fraction avec son parent ouvert', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2Partiel = reglerPosteTask5($this, $parent, 3000);
    $fraction = $parent->fractionsPosteTiers()->sole();

    $this->reglements->annuler((int) $t2Partiel->id);

    expect(Transaction::withTrashed()->find($t2Partiel->id))->toBeNull()
        ->and(montantCentimesTask5($parent->fresh()))->toBe(10000)
        ->and(TransactionLigne::find($fraction->id))->toBeNull()
        ->and($fraction->fresh()?->trashed())->toBeTrue()
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('laisse une fraction ouverte quand son parent a été réglé après elle', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $premierT2 = reglerPosteTask5($this, $parent, 3000);
    $fraction = $parent->fractionsPosteTiers()->sole();
    reglerPosteTask5($this, $parent->fresh(), 7000);

    $this->reglements->annuler((int) $premierT2->id);

    $poste = $this->postes->pourTransaction($t1->fresh(), 2025);

    expect($fraction->fresh()->lettrage_code)->toBeNull()
        ->and(montantCentimesTask5($fraction->fresh()))->toBe(3000)
        ->and($poste?->soldeCentimes)->toBe(3000);
});

it('refuse d annuler une T2 rapprochée sans mutation', function (): void {
    [, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['rapprochement_id' => $rapprochement->id]);

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, 'rapprochement');

    expect($t2->fresh())->not->toBeNull()
        ->and($parent->fresh()->lettrage_code)->not->toBeNull();
});

it('refuse d annuler une T2 liée à une remise sans mutation', function (): void {
    [, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 501,
        'date' => '2026-07-24',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise de protection annulation',
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['remise_id' => $remise->id]);

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, 'remise');

    expect($t2->fresh())->not->toBeNull()
        ->and($parent->fresh()->lettrage_code)->not->toBeNull();
});

it('refuse un groupe de lettrage tiers à trois lignes sans aucune mutation', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $codeLettrage = $parent->fresh()->lettrage_code;

    $intruse = $parent->replicate(['id', 'deleted_at']);
    $intruse->fill([
        'debit' => '1.00',
        'credit' => '0.00',
        'montant' => '0.00',
        'lettrage_code' => $codeLettrage,
        'libelle' => 'Ligne intrusive de contrôle',
    ]);
    $intruse->save();

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, 'exactement deux lignes');

    expect(Transaction::find((int) $t2->id)?->id)->toBe((int) $t2->id)
        ->and($parent->fresh()->lettrage_code)->toBe($codeLettrage)
        ->and($intruse->fresh()->lettrage_code)->toBe($codeLettrage)
        ->and(TransactionLigne::query()
            ->where('compte_id', (int) $parent->compte_id)
            ->where('lettrage_code', $codeLettrage)
            ->count())->toBe(3)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('refuse une T2 avec deux lettrages tiers distincts sans aucune mutation', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $codePrincipal = $parent->fresh()->lettrage_code;
    $codeSecondaire = 'T2-SECOND-LETTRAGE';
    $ligneTiersT2 = $t2->lignes()
        ->whereHas('compte', fn ($query) => $query->where('numero_pcg', '411'))
        ->sole();

    $contrepartieSecondaire = $parent->replicate(['id', 'deleted_at']);
    $contrepartieSecondaire->fill([
        'lettrage_code' => $codeSecondaire,
        'libelle' => 'Contrepartie du second lettrage T2',
    ]);
    $contrepartieSecondaire->save();

    $ligneTiersT2Secondaire = $ligneTiersT2->replicate(['id', 'deleted_at']);
    $ligneTiersT2Secondaire->fill([
        'lettrage_code' => $codeSecondaire,
        'libelle' => 'Seconde ligne tiers T2',
    ]);
    $ligneTiersT2Secondaire->save();

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, 'unique ligne tiers');

    expect(Transaction::find((int) $t2->id)?->id)->toBe((int) $t2->id)
        ->and($parent->fresh()->lettrage_code)->toBe($codePrincipal)
        ->and($ligneTiersT2->fresh()->lettrage_code)->toBe($codePrincipal)
        ->and($contrepartieSecondaire->fresh()->lettrage_code)->toBe($codeSecondaire)
        ->and($ligneTiersT2Secondaire->fresh()->lettrage_code)->toBe($codeSecondaire)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('refuse une contrepartie de lettrage supprimée sans aucune mutation', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $codeLettrage = $parent->fresh()->lettrage_code;

    $parent->delete();

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, 'supprimée');

    expect(Transaction::find((int) $t2->id)?->id)->toBe((int) $t2->id)
        ->and(TransactionLigne::withTrashed()->findOrFail((int) $parent->id)->lettrage_code)
        ->toBe($codeLettrage)
        ->and($t2->lignes()->whereHas('compte', fn ($query) => $query->where('numero_pcg', '411'))
            ->sole()
            ->lettrage_code)
        ->toBe($codeLettrage)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('refuse une paire de lettrage tiers incohérente sans aucune mutation', function (
    string $debit,
    string $credit,
    string $message,
): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $codeLettrage = $parent->fresh()->lettrage_code;
    $ligneTiersT2 = $t2->lignes()
        ->whereHas('compte', fn ($query) => $query->where('numero_pcg', '411'))
        ->sole();
    $ligneTiersT2->update(['debit' => $debit, 'credit' => $credit]);

    expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
        ->toThrow(RuntimeException::class, $message);

    expect(Transaction::find((int) $t2->id)?->id)->toBe((int) $t2->id)
        ->and($parent->fresh()->lettrage_code)->toBe($codeLettrage)
        ->and($ligneTiersT2->fresh()->lettrage_code)->toBe($codeLettrage)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
})->with([
    'montant différent' => ['0.00', '99.99', 'montants'],
    'même sens' => ['100.00', '0.00', 'sens'],
]);

it('restaure le lettrage et les écritures si la suppression échoue après le délettrage', function (): void {
    [$t1, $parent] = creerPosteAnnulableTask5($this);
    $t2 = reglerPosteTask5($this, $parent, 10000);
    $codeAvant = $parent->fresh()->lettrage_code;

    $connection = DB::connection();
    $dispatcherPrecedent = $connection->getEventDispatcher();
    $connection->setEventDispatcher(new Dispatcher($this->app));
    $connection->listen(static function (QueryExecuted $query): void {
        if (str_contains(strtolower($query->sql), 'delete from')
            && str_contains(strtolower($query->sql), 'transaction_lignes')) {
            throw new RuntimeException('Échec sentinelle après délettrage.');
        }
    });

    try {
        expect(fn (): mixed => $this->reglements->annuler((int) $t2->id))
            ->toThrow(RuntimeException::class, 'sentinelle');
    } finally {
        $connection->setEventDispatcher($dispatcherPrecedent);
    }

    expect($t2->fresh())->not->toBeNull()
        ->and($parent->fresh()->lettrage_code)->toBe($codeAvant)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
