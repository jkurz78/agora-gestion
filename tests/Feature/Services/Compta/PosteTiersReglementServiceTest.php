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
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    $montant = $montantCentimes / 100;

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
        (int) round((float) $ligne->debit * 100)
        - (int) round((float) $ligne->credit * 100)
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
        ->and((int) round((float) $lignesSource->sum('debit') * 100))
        ->toBe((int) round((float) $lignesSource->sum('credit') * 100))
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

    expect((int) round((float) $ligne401->debit * 100))->toBe(0)
        ->and((int) round((float) $ligne401->credit * 100))->toBe(7450)
        ->and((int) round((float) $fraction->debit * 100))->toBe(0)
        ->and((int) round((float) $fraction->credit * 100))->toBe(2550)
        ->and((int) round((float) $t2->montant_total * 100))->toBe(2550)
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
        ->and((int) round((float) $t2->montant_total * 100))->toBe(10000)
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
        ->and((int) round((float) $lignesActives->sum('debit') * 100))
        ->toBe((int) round((float) $lignesActives->sum('credit') * 100));
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
        ->and((int) round((float) $t2->montant_total * 100))->toBe(3000)
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

it('annule tout le découpage si le compte de trésorerie est introuvable', function (): void {
    [$t1, $ligne411] = creerPostePourReglementTask4($this, '411');
    $transactionsAvant = Transaction::count();

    expect(fn () => $this->serviceReglementPoste->regler(commandeReglementTask4(
        $ligne411,
        3000,
        999999999,
    )))->toThrow(RuntimeException::class, 'Aucun compte de trésorerie');

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
