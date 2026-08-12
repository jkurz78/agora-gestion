<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * Audit robustesse — point 2 : `immobilisations.transaction_id` et
 * `immobilisation_dotations.transaction_id` sont maintenant uniques (voir
 * migration 2026_08_12_100001_add_unique_transaction_id_to_immobilisations_tables).
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero('2188'),
            compteAmortissement: Compte::ofNumero('28188'),
            montant: '3000.00',
            dateAchat: Carbon::parse('2026-09-12'),
            dateMiseEnService: Carbon::parse('2026-09-12'),
            dureeMois: 60,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('refuse deux fiches pointant la même transaction d’acquisition', function (): void {
    $immo = ($this->creerImmo)();

    expect(fn () => Immobilisation::factory()->create([
        'transaction_id' => (int) $immo->transaction_id,
    ]))->toThrow(QueryException::class);
});

it('refuse deux dotations pointant la même transaction', function (): void {
    $immoA = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $dotationA = $immoA->dotations()->where('exercice', 2026)->firstOrFail();

    $immoB = ($this->creerImmo)();

    expect(fn () => ImmobilisationDotation::create([
        'immobilisation_id' => (int) $immoB->id,
        'exercice' => 2026,
        'montant' => '10.00',
        'transaction_id' => (int) $dotationA->transaction_id,
    ]))->toThrow(QueryException::class);
});

it('une fiche supprimée puis une nouvelle création ne se heurtent pas à l’index unique', function (): void {
    $immoSupprimee = ($this->creerImmo)();
    $ancienTransactionId = (int) $immoSupprimee->transaction_id;

    app(ImmobilisationService::class)->supprimer($immoSupprimee);

    // La fiche et sa transaction sont soft-deleted, transaction_id inchangé
    // sur la ligne fantôme — l'index unique porte sur toutes les lignes,
    // supprimées ou non (SQLite comme MySQL). Le point à vérifier : une
    // nouvelle acquisition, qui reçoit toujours une transaction FRAÎCHE
    // (nouvel ID auto-incrémenté, jamais celui de la fiche supprimée), ne
    // doit heurter ni cet index ni celui sur (association_id, numero).
    $nouvelleImmo = ($this->creerImmo)();

    expect($nouvelleImmo->id)->not->toBe($immoSupprimee->id)
        ->and((int) $nouvelleImmo->transaction_id)->not->toBe($ancienTransactionId)
        ->and(Immobilisation::withTrashed()->count())->toBe(2)
        ->and(Immobilisation::count())->toBe(1);
});
