<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\OrigineANouveau;
use App\Enums\SensVentilation;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Livewire\Exercices\ReouvrirExercice;
use App\Livewire\RemiseBancaireSelection;
use App\Models\ANouveauGeneration;
use App\Models\ANouveauLigneOrigine;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Incident du 2026-10-20 (créance #190) : une créance de N, reportée en à-nouveau,
 * réglée en espèces en N+1 depuis la fenêtre des postes tiers.
 */

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

    $this->tiers = Tiers::factory()->create(['nom' => 'Notz', 'prenom' => 'Mircea']);
    $this->creance = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['sens' => SensVentilation::Credit, 'compte' => $this->compte706, 'montant' => 40.00]],
        dateConstatation: new DateTimeImmutable('2026-06-20'),
        libelle: 'Séance 5',
    );
});

function reporterCreancesEn2026(object $ctx): ANouveauGeneration
{
    return app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $ctx->user,
    );
}

function reglerCreanceReporteeEnEspeces(object $ctx): Transaction
{
    $poste = app(PostesTiersOuvertsService::class)->pourTransaction($ctx->creance, 2026);

    return app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
        ligneId: $poste->ligneActionId,
        montantCentimes: $poste->soldeCentimes,
        date: CarbonImmutable::parse('2026-10-20'),
        mode: ModePaiement::Especes,
        compteBancaireId: (int) $ctx->compteBancaire->id,
        exercice: 2026,
    ));
}

it('propose et remet en banque une créance reportée réglée en espèces depuis le poste tiers', function (): void {
    reporterCreancesEn2026($this);
    reglerCreanceReporteeEnEspeces($this);

    expect($this->creance->fresh()->mode_paiement)->toBeNull()
        ->and($this->creance->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);

    $remise = app(RemiseBancaireService::class)->creer([
        'date' => '2026-10-21',
        'mode_paiement' => ModePaiement::Especes->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $remise])
        ->assertViewHas('transactions', fn ($transactions): bool => $transactions->pluck('id')->all() === [(int) $this->creance->id])
        ->call('toggleAll')
        ->assertSet('selectedTransactionIds', [(int) $this->creance->id]);

    app(RemiseBancaireService::class)->comptabiliser($remise, [(int) $this->creance->id]);

    expect(app(EtatReglementResolver::class)->resolve($this->creance->fresh()))->toBe(StatutReglement::Recu)
        ->and($remise->fresh()->comptabilisee_at)->not->toBeNull();
});

it('refuse de réouvrir quand un à-nouveau est déjà lettré par un règlement de N+1', function (): void {
    $generation = reporterCreancesEn2026($this);
    reglerCreanceReporteeEnEspeces($this);
    $exercice2025 = Exercice::where('annee', 2025)->firstOrFail();

    expect(fn () => app(ANouveauService::class)->invalider($exercice2025, $this->user, 'erreur'))
        ->toThrow(ANouveauInvalideException::class);

    $ligneAN = $generation->origines()->firstOrFail()->ligneAN()->first();
    expect($ligneAN)->not->toBeNull()
        ->and($ligneAN->lettrage_code)->not->toBeNull()
        ->and($generation->fresh()->statut->value)->toBe('active');
});

it('explique le refus à l écran de réouverture, sans rouvrir', function (): void {
    reporterCreancesEn2026($this);
    $t2 = reglerCreanceReporteeEnEspeces($this);
    Exercice::where('annee', 2025)->update(['statut' => StatutExercice::Cloture->value, 'date_cloture' => now()]);
    session(['exercice_actif' => 2025]);

    Livewire::test(ReouvrirExercice::class)
        ->set('commentaire', 'Règlement saisi par erreur')
        ->call('reouvrir')
        ->assertHasErrors('reouverture')
        ->assertSee($t2->fresh()->numero_piece);

    expect(Exercice::where('annee', 2025)->firstOrFail()->statut)->toBe(StatutExercice::Cloture);
});

it('laisse modifier la créance d origine après invalidation de ses à-nouveaux', function (): void {
    reporterCreancesEn2026($this);
    app(ANouveauService::class)->invalider(Exercice::where('annee', 2025)->firstOrFail(), $this->user, 'correction');

    $ligne706 = TransactionLigne::where('transaction_id', $this->creance->id)
        ->where('compte_id', $this->compte706->id)->sole();

    app(TransactionService::class)->update($this->creance->fresh(), [
        'type' => 'recette', 'date' => '2026-06-20', 'libelle' => 'Séance 5 corrigée',
        'montant_total' => 40, 'mode_paiement' => null, 'tiers_id' => $this->tiers->id,
        'reference' => null, 'compte_id' => $this->compteBancaire->id, 'notes' => null,
    ], [[
        'id' => $ligne706->id, 'compte_id' => $this->compte706->id,
        'operation_id' => null, 'seance' => null, 'montant' => '40.00', 'notes' => null,
    ]]);

    expect($this->creance->fresh()->libelle)->toBe('Séance 5 corrigée')
        ->and(ANouveauLigneOrigine::count())->toBe(0);
});
