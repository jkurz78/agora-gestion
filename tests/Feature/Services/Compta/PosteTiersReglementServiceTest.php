<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->generatorReglementPoste = app(EcritureGenerator::class);
    $this->serviceReglementPoste = app(PosteTiersReglementService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * @return array{Transaction, TransactionLigne}
 */
function creerPostePourReglementTask4(object $contexte, string $numeroCompte, int $montantCentimes = 10000): array
{
    $tiers = Tiers::factory()->create(['association_id' => $contexte->association->id]);
    $montant = MontantDecimal::depuisCentimes($montantCentimes);

    $transaction = $numeroCompte === '411'
        ? $contexte->generatorReglementPoste->pourRecetteACredit(
            tiers: $tiers,
            ventilations: [['compte' => $contexte->compte706, 'montant' => $montant]],
            dateConstatation: new DateTimeImmutable('2025-10-01'),
            libelle: 'Créance à régler partiellement',
        )
        : $contexte->generatorReglementPoste->pourDepenseACredit(
            tiers: $tiers,
            ventilations: [['compte' => $contexte->compte606, 'montant' => $montant]],
            dateConstatation: new DateTimeImmutable('2025-10-01'),
            libelle: 'Dette à régler partiellement',
        );

    $transaction->update(['statut_reglement' => StatutReglement::EnAttente]);
    $ligneTiers = $transaction->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === $numeroCompte
    );

    return [$transaction->fresh(), $ligneTiers];
}

function commandeReglementTask4(
    TransactionLigne $ligne,
    int $montantCentimes,
    int $compteBancaireId,
    ModePaiement $mode = ModePaiement::Virement,
    string $date = '2026-07-23',
    int $exercice = 2025,
): PosteTiersReglementData {
    return new PosteTiersReglementData(
        ligneId: (int) $ligne->id,
        montantCentimes: $montantCentimes,
        date: CarbonImmutable::parse($date),
        mode: $mode,
        compteBancaireId: $compteBancaireId,
        exercice: $exercice,
    );
}

function montantLigneCentimesTask4(TransactionLigne $ligne): int
{
    return abs(
        MontantDecimal::versCentimes((string) $ligne->debit)
        - MontantDecimal::versCentimes((string) $ligne->credit)
    );
}

it('encaisse partiellement une créance 411 en conservant le reliquat canonique et l équilibre', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $idCanonique = (int) $ligne411->id;
    $ligneProduitAvant = $t1->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '706'
    );
    $affectation = $ligneProduitAvant->affectations()->create([
        'operation_id' => null,
        'seance' => 7,
        'montant' => '100.00',
        'notes' => 'Affectation sentinelle du test de règlement',
    ]);

    $t2 = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        (int) $this->compteBancaire->id,
    ));

    $ligne411->refresh();
    $fraction = $ligne411->fractionsPosteTiers()->sole();
    $lignesSource = $t1->lignes()->get();

    expect((int) $ligne411->id)->toBe($idCanonique)
        ->and(montantLigneCentimesTask4($ligne411))->toBe(7000)
        ->and(montantLigneCentimesTask4($fraction))->toBe(3000)
        ->and((int) $fraction->poste_tiers_parent_id)->toBe($idCanonique)
        ->and($fraction->lettrage_code)->not->toBeNull()
        ->and($t2->date->toDateString())->toBe('2026-07-23')
        ->and($t2->mode_paiement)->toBe(ModePaiement::Virement)
        ->and($t2->compte_id)->toBe((int) $this->compteBancaire->id)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente)
        ->and(montantLigneCentimesTask4($ligneProduitAvant->fresh()))->toBe(10000)
        ->and($lignesSource->reduce(
            fn (string $total, TransactionLigne $ligne): string => bcadd($total, (string) $ligne->debit, 2),
            '0.00',
        ))
        ->toBe($lignesSource->reduce(
            fn (string $total, TransactionLigne $ligne): string => bcadd($total, (string) $ligne->credit, 2),
            '0.00',
        ))
        ->and($ligneProduitAvant->affectations()->sole()->is($affectation))->toBeTrue()
        ->and($affectation->fresh()->only(['seance', 'montant', 'notes']))->toBe([
            'seance' => 7,
            'montant' => '100.00',
            'notes' => 'Affectation sentinelle du test de règlement',
        ]);
});

it('règle partiellement une dette 401 du même côté crédit', function (): void {
    [$t1, $ligne401] = creerPostePourReglementTask4($this, '401');

    $t2 = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne401,
        2550,
        (int) $this->compteBancaire->id,
    ));

    $ligne401->refresh();
    $fraction = $ligne401->fractionsPosteTiers()->sole();

    expect(montantLigneCentimesTask4($ligne401))->toBe(7450)
        ->and($ligne401->debit)->toBe('0.00')
        ->and(montantLigneCentimesTask4($fraction))->toBe(2550)
        ->and($fraction->debit)->toBe('0.00')
        ->and(MontantDecimal::versCentimes((string) $t2->montant_total))->toBe(2550)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('solde totalement un poste sans créer de sœur', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');

    $t2 = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        10000,
        (int) $this->compteBancaire->id,
    ));

    expect($ligne411->fresh()->lettrage_code)->not->toBeNull()
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0)
        ->and(MontantDecimal::versCentimes((string) $t2->montant_total))->toBe(10000)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu)
        ->and(app(PostesTiersOuvertsService::class)->pourTransaction($t1->fresh(), 2025))->toBeNull();
});

it('enchaîne deux règlements partiels en gardant le même identifiant de reliquat', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $idCanonique = (int) $ligne411->id;

    $premier = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        (int) $this->compteBancaire->id,
    ));
    $second = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411->fresh(),
        2000,
        (int) $this->compteBancaire->id,
    ));

    $ligne411->refresh();
    $fractions = $ligne411->fractionsPosteTiers()->orderBy('id')->get();

    expect((int) $ligne411->id)->toBe($idCanonique)
        ->and(montantLigneCentimesTask4($ligne411))->toBe(5000)
        ->and($fractions)->toHaveCount(2)
        ->and($fractions->map(fn (TransactionLigne $ligne): int => montantLigneCentimesTask4($ligne))->all())
        ->toBe([3000, 2000])
        ->and($fractions->every(fn (TransactionLigne $ligne): bool => $ligne->lettrage_code !== null))->toBeTrue()
        ->and((int) $premier->id)->not->toBe((int) $second->id)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('consolide toutes les fractions ouvertes avant un nouveau découpage sans déséquilibrer la pièce', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $ligne411->update(['debit' => '60.00', 'credit' => '0.00']);
    $fractionOuverte = $ligne411->replicate(['id', 'lettrage_code', 'deleted_at']);
    $fractionOuverte->fill([
        'debit' => '40.00',
        'credit' => '0.00',
        'poste_tiers_parent_id' => (int) $ligne411->id,
    ]);
    $fractionOuverte->save();

    $this->serviceReglementPoste->regler(commandeReglementTask4(
        $fractionOuverte,
        3000,
        (int) $this->compteBancaire->id,
    ));

    $ligne411->refresh();
    $lignesActives = $t1->lignes()->get();
    $fractionsActives = $ligne411->fractionsPosteTiers()->get();

    expect($fractionOuverte->fresh()->trashed())->toBeTrue()
        ->and(montantLigneCentimesTask4($ligne411))->toBe(7000)
        ->and($fractionsActives)->toHaveCount(1)
        ->and(montantLigneCentimesTask4($fractionsActives->sole()))->toBe(3000)
        ->and($lignesActives->reduce(
            fn (string $total, TransactionLigne $ligne): string => bcadd($total, (string) $ligne->debit, 2),
            '0.00',
        ))
        ->toBe($lignesActives->reduce(
            fn (string $total, TransactionLigne $ligne): string => bcadd($total, (string) $ligne->credit, 2),
            '0.00',
        ));
});

it('règle partiellement le descendant AN actif sans modifier la ligne historique', function (): void {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    [$t1, $source411] = creerPostePourReglementTask4($this, '411', 8000);
    $t1->update(['date' => '2026-08-20']);

    $generation = app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $this->user,
    );
    $ligneAN = $generation->origines()
        ->with('ligneAN.compte')
        ->where('ligne_racine_id', (int) $source411->id)
        ->firstOrFail()
        ->ligneAN;

    $t2 = $this->serviceReglementPoste->regler(new PosteTiersReglementData(
        ligneId: (int) $ligneAN->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2026-09-05'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2026,
    ));

    $ligneAN->refresh();
    $fractionPayee = $ligneAN->fractionsPosteTiers()->sole();

    expect($source411->fresh()->lettrage_code)->toBeNull()
        ->and(montantLigneCentimesTask4($source411->fresh()))->toBe(8000)
        ->and($ligneAN->lettrage_code)->toBeNull()
        ->and(montantLigneCentimesTask4($ligneAN))->toBe(5000)
        ->and(montantLigneCentimesTask4($fractionPayee))->toBe(3000)
        ->and((int) $fractionPayee->poste_tiers_parent_id)->toBe((int) $ligneAN->id)
        ->and($fractionPayee->lettrage_code)->not->toBeNull()
        ->and(MontantDecimal::versCentimes((string) $t2->montant_total))->toBe(3000)
        ->and($t2->date->toDateString())->toBe('2026-09-05')
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('refuse les montants nuls négatifs ou supérieurs au solde sans mutation', function (int $montant): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $transactionsAvant = Transaction::count();

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        $montant,
        (int) $this->compteBancaire->id,
    )))->toThrow(InvalidArgumentException::class, 'montant');

    expect(montantLigneCentimesTask4($ligne411->fresh()))->toBe(10000)
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0)
        ->and($ligne411->fresh()->lettrage_code)->toBeNull()
        ->and(Transaction::count())->toBe($transactionsAvant)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
})->with([
    'zéro' => 0,
    'négatif' => -1,
    'supérieur' => 10001,
]);

it('refuse une date hors de l exercice explicite', function (): void {
    [, $ligne411] = creerPostePourReglementTask4($this, '411');

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        (int) $this->compteBancaire->id,
        date: '2026-09-01',
        exercice: 2025,
    )))->toThrow(InvalidArgumentException::class, 'date');

    expect(montantLigneCentimesTask4($ligne411->fresh()))->toBe(10000)
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0);
});

it('refuse un règlement dans un exercice clôturé', function (): void {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
    [, $ligne401] = creerPostePourReglementTask4($this, '401');

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne401,
        10000,
        (int) $this->compteBancaire->id,
    )))->toThrow(ExerciceCloturedException::class);

    expect($ligne401->fresh()->lettrage_code)->toBeNull();
});

it('annule tout le découpage si le compte bancaire est introuvable', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $transactionsAvant = Transaction::count();

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        999999999,
    )))->toThrow(ModelNotFoundException::class);

    expect(montantLigneCentimesTask4($ligne411->fresh()))->toBe(10000)
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0)
        ->and($ligne411->fresh()->lettrage_code)->toBeNull()
        ->and(Transaction::count())->toBe($transactionsAvant)
        ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('refuse un poste appartenant à une autre association sans fuite ni mutation', function (): void {
    $associationLocale = $this->association;
    $associationEtrangere = Association::factory()->create();
    TenantContext::boot($associationEtrangere);
    SystemeSeeder::seed();
    $tiersEtranger = Tiers::factory()->create(['association_id' => $associationEtrangere->id]);
    $produitEtranger = Compte::create([
        'numero_pcg' => '706-E',
        'intitule' => 'Produit étranger',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $transactionEtrangere = $this->generatorReglementPoste->pourRecetteACredit(
        tiers: $tiersEtranger,
        ventilations: [['compte' => $produitEtranger, 'montant' => 100.00]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
    );
    $ligneEtrangere = $transactionEtrangere->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '411'
    );
    $transactionsAvant = Transaction::withoutGlobalScopes()->count();

    TenantContext::boot($associationLocale);

    expect(fn () => $this->serviceReglementPoste->regler(new PosteTiersReglementData(
        ligneId: (int) $ligneEtrangere->id,
        montantCentimes: 10000,
        date: CarbonImmutable::parse('2026-07-23'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    )))->toThrow(ModelNotFoundException::class);

    expect(Transaction::withoutGlobalScopes()->count())->toBe($transactionsAvant)
        ->and(
            TransactionLigne::withoutGlobalScopes()
                ->findOrFail((int) $ligneEtrangere->id)
                ->lettrage_code
        )->toBeNull();
});

it('refuse un compte bancaire d une autre association avant toute mutation même pour un chèque', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $associationLocale = $this->association;
    $associationEtrangere = Association::factory()->create();
    TenantContext::boot($associationEtrangere);
    $compteBancaireEtranger = CompteBancaire::factory()->create([
        'association_id' => $associationEtrangere->id,
    ]);
    TenantContext::boot($associationLocale);
    $transactionsAvant = Transaction::withoutGlobalScopes()->count();

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        (int) $compteBancaireEtranger->id,
        ModePaiement::Cheque,
    )))->toThrow(ModelNotFoundException::class);

    expect(Transaction::withoutGlobalScopes()->count())->toBe($transactionsAvant)
        ->and($ligne411->fresh()->debit)->toBe('100.00')
        ->and($ligne411->fresh()->lettrage_code)->toBeNull()
        ->and($ligne411->fractionsPosteTiers()->count())->toBe(0)
        ->and($t1->fresh()->compte_id)->not->toBe((int) $compteBancaireEtranger->id);
});

it('reprend depuis la racine quand la fraction ciblée a déjà été consolidée par une tentative concurrente', function (): void {
    [, $racine] = creerPostePourReglementTask4($this, '411');
    $racine->update(['debit' => '60.00']);
    $fractionDisparue = $racine->replicate(['id', 'lettrage_code', 'deleted_at']);
    $fractionDisparue->fill([
        'debit' => '40.00',
        'credit' => '0.00',
        'poste_tiers_parent_id' => (int) $racine->id,
    ]);
    $fractionDisparue->save();
    $fractionDisparue->delete();

    $t2 = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $fractionDisparue,
        1000,
        (int) $this->compteBancaire->id,
    ));

    expect($racine->fresh()->debit)->toBe('50.00')
        ->and($racine->fractionsPosteTiers()->sole()->debit)->toBe('10.00')
        ->and($t2->montant_total)->toBe('10.00');
});

it('conserve exactement les centimes sans décision monétaire en virgule flottante', function (): void {
    [, $ligne411] = creerPostePourReglementTask4($this, '411', 29);

    $t2 = $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        1,
        (int) $this->compteBancaire->id,
    ));

    $fraction = $ligne411->fractionsPosteTiers()->sole();

    expect($ligne411->fresh()->debit)->toBe('0.28')
        ->and($fraction->debit)->toBe('0.01')
        ->and($t2->montant_total)->toBe('0.01')
        ->and($t2->lignes()->orderBy('id')->pluck('debit')->all())->toBe(['0.01', '0.00'])
        ->and($t2->lignes()->orderBy('id')->pluck('credit')->all())->toBe(['0.00', '0.01']);
});

it('verrouille l exercice source avant de charger le lot de lignes du poste', function (): void {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    [, $ligne411] = creerPostePourReglementTask4($this, '411');
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        10000,
        (int) $this->compteBancaire->id,
    ));

    $requetes = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $sql): string => strtolower(str_replace(['"', '`'], '', $sql)))
        ->values();
    DB::disableQueryLog();
    $indexExercice = $requetes->search(
        fn (string $sql): bool => str_contains($sql, 'from exercices')
    );
    $indexLot = $requetes->search(
        fn (string $sql): bool => str_contains($sql, 'from transaction_lignes')
            && str_contains($sql, 'poste_tiers_parent_id')
    );

    expect($indexExercice)->not->toBeFalse()
        ->and($indexLot)->not->toBeFalse()
        ->and((int) $indexExercice)->toBeLessThan((int) $indexLot);
});
