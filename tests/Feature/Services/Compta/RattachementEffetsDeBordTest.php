<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
|--------------------------------------------------------------------------
| Les effets de bord d'un rattachement (spec § 8) : ce qu'il ne doit pas toucher
|--------------------------------------------------------------------------
|
| Deux affirmations du § 8, vérifiées sur de VRAIS rattachements — par RattachementExerciceService::rattacher(),
| sur des pièces créées par TransactionService::create() comme le fait un utilisateur (partie double complète,
| tiers, règlement, lettrage) :
|
|  - le statut de règlement et le lettrage de la pièce d'origine sont inchangés : le rattachement n'écrit que
|    deux nouvelles pièces d'inventaire (OD) et ne touche ni le 411/401 ni la trésorerie ;
|  - compta:assert-pd-complete ne trouve rien à redire aux écritures de rattachement.
|
| Vérification dans le code (Task 13) : EtatReglementResolver ne lit que la ligne de poste tiers de la pièce
| (PosteReporteResolver : 401 et 411), son lettrage, le poste ouvert du tiers (PostesTiersOuvertsService : 401 et
| 411) et la ligne de trésorerie atteinte par le lettrage (classe 5 : 5112, 530, 512X). Aucune lecture de 6xx, 7xx,
| 486 ni 487. Le test ci-dessous le garde sur le résultat, pas sur la lecture du code.
|
| Les pièces de la compta ne dépendent pas d'un compte de résultat fabriqué : le 706 et le 606 viennent du
| contexte CreatesPartieDoubleContext.
*/

beforeEach(function () {
    $this->setupPartieDoubleContext();

    foreach ([2025, 2026] as $annee) {
        Exercice::create(['association_id' => $this->association->id, 'annee' => $annee, 'statut' => StatutExercice::Ouvert]);
    }
    session(['exercice_actif' => 2025]);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

/**
 * Une pièce de recette (706, 175 €) ou de dépense (606, 90 €) du 08/04/2026, dans l'exercice 2025, créée par
 * le service de saisie : sans mode de paiement c'est une créance ou une dette (411 ou 401 ouvert) ; avec un
 * virement, la pièce est réglée (poste lettré, 512X). Retourne la pièce d'origine.
 */
function effetsDeBordPiece(object $test, int $classe, ?ModePaiement $mode): Transaction
{
    $recette = $classe === 7;

    return app(TransactionService::class)->create([
        'type' => ($recette ? TypeTransaction::Recette : TypeTransaction::Depense)->value,
        'date' => '2026-04-08',
        'libelle' => $recette ? 'Parcours thérapeutique 2026-2027' : 'Assurance 2026-2027',
        'montant_total' => $recette ? '175.00' : '90.00',
        'mode_paiement' => $mode?->value,
        'tiers_id' => $test->tiers->id,
        'compte_id' => $mode === null ? null : $test->compteBancaire->id,
    ], [[
        'compte_id' => ($recette ? $test->compte706 : $test->compte606)->id,
        'montant' => $recette ? '175.00' : '90.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
}

/** La ligne de produit ou de charge d'une pièce : celle que le rattachement prend pour origine. */
function effetsDeBordLigneDOrigine(Transaction $piece, int $classe): TransactionLigne
{
    return $piece->lignes()->whereHas('compte', fn ($compte) => $compte->where('classe', $classe))->sole();
}

dataset('rattachement_effets_de_bord_reglement', [
    'PCA, pièce en créance (411 ouvert)' => [7, null, StatutReglement::EnAttente, false],
    'PCA, pièce réglée par virement (411 lettré, 512X)' => [7, ModePaiement::Virement, StatutReglement::Recu, true],
    'CCA, pièce en dette (401 ouvert)' => [6, null, StatutReglement::EnAttente, false],
    'CCA, pièce réglée par virement (401 lettré, 512X)' => [6, ModePaiement::Virement, StatutReglement::Recu, true],
]);

// ── Statut de règlement et lettrage ──────────────────────────────────────────

it('laisse le statut de règlement et le lettrage de la pièce d’origine tels qu’ils étaient, et n’écrit rien sur les écritures existantes', function (int $classe, ?ModePaiement $mode, StatutReglement $attendu, bool $lettre): void {
    $piece = effetsDeBordPiece($this, $classe, $mode);
    $ligne = effetsDeBordLigneDOrigine($piece, $classe);
    $resolver = app(EtatReglementResolver::class);
    $poste = fn (): TransactionLigne => $piece->lignes()
        ->whereHas('compte', fn ($compte) => $compte->whereIn('numero_pcg', ['401', '411']))
        ->sole();

    // Garde anti-test-vide : la pièce part du statut du cas, avec son poste tiers lettré ou non.
    expect($resolver->resolve($piece->fresh()))->toBe($attendu);
    expect($piece->fresh()->statut_reglement)->toBe($attendu);
    expect($poste()->lettrage_code !== null)->toBe($lettre);
    $codeAvant = $poste()->lettrage_code;

    $dernierePiece = (int) Transaction::max('id');
    $derniereLigne = (int) TransactionLigne::max('id');
    $piecesAvant = Transaction::query()->orderBy('id')->get()->toArray();
    $lignesAvant = TransactionLigne::query()->orderBy('id')->get()->toArray();

    app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

    // Le statut, dérivé du grand livre comme stocké, et le lettrage du poste tiers n'ont pas bougé.
    expect($resolver->resolve($piece->fresh()))->toBe($attendu);
    expect($piece->fresh()->statut_reglement)->toBe($attendu);
    expect($poste()->lettrage_code)->toBe($codeAvant);

    // Plus fort : aucune pièce ni aucune ligne qui existait avant n'a été réécrite, d'une seule colonne.
    expect(Transaction::query()->where('id', '<=', $dernierePiece)->orderBy('id')->get()->toArray())->toBe($piecesAvant);
    expect(TransactionLigne::query()->where('id', '<=', $derniereLigne)->orderBy('id')->get()->toArray())->toBe($lignesAvant);

    // Le rattachement n'a écrit que ses deux pièces et leurs quatre lignes : le compte d'origine et un compte de
    // régularisation (486 ou 487) — jamais un 411, un 401, un compte de trésorerie.
    expect(Transaction::query()->where('id', '>', $dernierePiece)->count())->toBe(2);

    $nouvelles = TransactionLigne::query()->where('id', '>', $derniereLigne)->with('compte')->get();

    expect($nouvelles)->toHaveCount(4);
    expect($nouvelles->pluck('compte.numero_pcg')->sort()->values()->all())->toBe(
        collect([$ligne->compte->numero_pcg, $ligne->compte->numero_pcg, $classe === 7 ? '487' : '486', $classe === 7 ? '487' : '486'])->sort()->values()->all()
    );
})->with('rattachement_effets_de_bord_reglement');

// ── compta:assert-pd-complete ────────────────────────────────────────────────

it('compta:assert-pd-complete ne trouve rien à redire après un rattachement PCA et un rattachement CCA, écritures de rattachement comprises', function (): void {
    $pca = effetsDeBordPiece($this, 7, ModePaiement::Virement);
    $cca = effetsDeBordPiece($this, 6, null);

    app(RattachementExerciceService::class)->rattacher(effetsDeBordLigneDOrigine($pca, 7), null, $this->user);
    app(RattachementExerciceService::class)->rattacher(effetsDeBordLigneDOrigine($cca, 6), null, $this->user);

    // Garde anti-test-vide : quatre écritures de rattachement existent, sans HelloAsso — le guard les inspecte.
    expect(Transaction::query()->whereNotNull('provision_id')->count())->toBe(4);
    expect(Transaction::query()->whereNotNull('provision_id')->whereNotNull('helloasso_order_id')->count())->toBe(0);

    // `--check` est indispensable : sans lui la commande rend 0 même quand elle signale des anomalies.
    $association = TenantContext::current();
    $code = Artisan::call('compta:assert-pd-complete', ['--check' => true]);
    $texte = Artisan::output();

    // La commande réamorce le tenant à chaque association : on remet le nôtre.
    TenantContext::boot($association);

    expect($texte)->toContain('Toutes les transactions sont PD-conformes.');
    expect($texte)->not->toContain('incomplète');
    expect($texte)->not->toContain('Tx #');
    expect($code)->toBe(0);
});
