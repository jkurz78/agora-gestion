<?php

declare(strict_types=1);

// L'AG vote en octobre, sur un exercice qui vient de demarrer : le realise
// est a zero et aucune ventilation n'existe. Les deux cases decochees
// produisent le budget vote ; cochees, le suivi de mars.
//
// Piege signale par le plan : le PDF est binaire et dompdf peut compresser —
// `not->toContain()` sur $response->getContent() passerait quoi qu'il arrive
// et ne prouverait rien. Voie choisie ici : la vue Blade pdf.rapport-budget
// est rendue DIRECTEMENT avec les memes structures de donnees que
// RapportExportController::pdfBudgetData() construit — via le meme builder
// partage App\Services\Rapports\BudgetEcranBuilder, aussi appele par
// App\Livewire\BudgetTable::render() — et les assertions portent sur le HTML
// produit. Les tests HTTP, eux, se limitent a verifier le statut 200 et le
// bon aiguillage du registre (ils ne prouvent rien sur le contenu).

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\BudgetEcranBuilder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\View;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);

    $this->compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Stage de printemps',
    ]);

    // Une enveloppe (operation_id null)...
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'operation_id' => null,
        'exercice' => 2025,
        'montant_prevu' => 1000.00,
    ]);
    // ...et sa ventilation.
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'operation_id' => $this->operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    // Un mouvement reel sur ce compte et cette operation : sans lui, realise
    // et ecart resteraient a zero des deux cotes et ne distingueraient rien.
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte->id,
        'operation_id' => $this->operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

/**
 * Reconstruit EXACTEMENT les donnees que
 * RapportExportController::pdfBudgetData() construit pour l'exercice donne,
 * pour rendre la vue pdf.rapport-budget hors du controleur — sans dependre
 * du rendu PDF binaire. Appelle desormais le meme builder que le controleur
 * et App\Livewire\BudgetTable::render() (App\Services\Rapports\BudgetEcranBuilder) :
 * ce helper est un vrai temoin du contrat, pas une quatrieme copie des six
 * requetes.
 *
 * @return array<string, mixed>
 */
function budgetPdfViewData(int $exercice, bool $avecRealise, bool $avecVentilations): array
{
    $donnees = app(BudgetEcranBuilder::class)->pourExercice($exercice);

    return array_merge($donnees, [
        'avecRealise' => $avecRealise,
        'avecVentilations' => $avecVentilations,
        'title' => 'Budget',
        'subtitle' => 'Exercice 2025-2026',
        'association' => null,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ]);
}

it('le budget vote ne montre ni realise ni ventilation', function (): void {
    $html = view('pdf.rapport-budget', budgetPdfViewData(2025, avecRealise: false, avecVentilations: false))->render();

    expect($html)->not->toContain('Stage de printemps')
        ->and($html)->not->toContain('Réalisé');
});

it('le suivi montre les ventilations et le realise', function (): void {
    $html = view('pdf.rapport-budget', budgetPdfViewData(2025, avecRealise: true, avecVentilations: true))->render();

    expect($html)->toContain('Stage de printemps')
        ->and($html)->toContain('Réalisé');
});

it('le drapeau realise seul ajoute la colonne sans montrer la ventilation', function (): void {
    // Sans ce cas, `avecRealise` pourrait ne rien piloter du tout et le test
    // "suivi" ci-dessus passerait pour de mauvaises raisons (a cause du seul
    // drapeau ventilations).
    $html = view('pdf.rapport-budget', budgetPdfViewData(2025, avecRealise: true, avecVentilations: false))->render();

    expect($html)->toContain('Réalisé')
        ->and($html)->not->toContain('Stage de printemps');
});

it('le drapeau ventilations seul montre l operation sans la colonne realise', function (): void {
    // Symetrique du precedent : sans ce cas, `avecVentilations` pourrait ne
    // rien piloter du tout.
    $html = view('pdf.rapport-budget', budgetPdfViewData(2025, avecRealise: false, avecVentilations: true))->render();

    expect($html)->toContain('Stage de printemps')
        ->and($html)->not->toContain('Réalisé');
});

it('la route pdf du budget repond 200 pour les quatre combinaisons de drapeaux', function (): void {
    foreach ([['0', '0'], ['1', '1'], ['1', '0'], ['0', '1']] as [$realise, $ventilations]) {
        $this->get(route('rapports.export', [
            'rapport' => 'budget',
            'format' => 'pdf',
            'exercice' => 2025,
            'realise' => $realise,
            'ventilations' => $ventilations,
        ]))->assertOk();
    }
});

it('le pdf recoit bien les enveloppes (pas les ventilations) et les bons drapeaux, capture a travers dompdf', function (): void {
    // Garde-fou indispensable meme apres factorisation : c'est la seule
    // assertion qui traverse reellement pdfBudgetData() jusqu'a la vue — les
    // tests precedents rendent la vue directement avec budgetPdfViewData(),
    // jamais via le controleur. Sans ce test, un enveloppes() -> ventilations()
    // dans pdfBudgetData() (le bug historique du budget double, imprime en
    // AG) ou une inversion des deux drapeaux lus depuis la requete HTTP
    // passeraient inapercus : les 162 tests existants restent verts.
    $captured = null;
    View::composer('pdf.rapport-budget', function ($view) use (&$captured): void {
        $captured = $view->getData();
    });

    $this->get(route('rapports.export', [
        'rapport' => 'budget', 'format' => 'pdf', 'exercice' => 2025,
        'realise' => '1', 'ventilations' => '0',
    ]))->assertOk();

    expect($captured)->not->toBeNull();
    expect($captured['avecRealise'])->toBeTrue();
    expect($captured['avecVentilations'])->toBeFalse();

    // L'enveloppe (1000 €, operation_id null) doit etre la ligne recue pour
    // ce compte — pas sa ventilation (300 €, operation_id renseigne) : sinon
    // le budget imprime en AG serait celui, plus petit, d'une seule
    // operation, jamais alerte par aucun test.
    $montant = (float) $captured['budgetLines']->get($this->compte->id)->montant_prevu;
    expect($montant)->toBe(1000.0)->and($montant)->not->toBe(300.0);
});

it('le gabarit d aller-retour reste xlsx et csv seulement, jamais la ventilation', function (): void {
    // La ventilation ne doit JAMAIS entrer dans le fichier reimportable : un
    // fichier de septembre reimporte en mars ecraserait six mois de travail.
    $csv = $this->get(route('comptabilite.budget.export', [
        'format' => 'csv',
        'exercice' => 2025,
        'source' => 'zero',
    ]));

    $csv->assertOk();
    expect($csv->getContent())->not->toContain('Stage de printemps');
});
