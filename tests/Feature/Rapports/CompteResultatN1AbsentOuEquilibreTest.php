<?php

declare(strict_types=1);

/**
 * `$resultatCourantN1` confondait deux situations bien distinctes :
 *
 *   - aucune donnée N-1 nulle part (première année d'une association) —
 *     Collection::sum() rend 0 sur une colonne entièrement à `null`, jamais
 *     `null`, alors que CompteResultatBuilder::sommeBudgetSection() sait déjà
 *     faire cette distinction pour le budget ;
 *   - un exercice N-1 réellement à l'équilibre (charges N-1 = produits N-1,
 *     résultat net 0.0) — un vrai zéro, pas une absence.
 *
 * Le masque d'affichage `$resultatCourantN1 != 0 ? … : '—'` aggravait la
 * confusion : il traitait les DEUX cas comme un tiret, alors que seul le
 * premier doit l'être.
 *
 * Ce test prouve la distinction sur les deux surfaces qui exposent la ligne
 * RÉSULTAT : l'écran Livewire (App\Livewire\RapportCompteResultat) et
 * l'export XLSX (RapportExportController::xlsxCompteResultat).
 */

use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ligne de ventilation compte-first, une transaction dédiée par appel (les
 * lignes par défaut de la factory sont supprimées par l'appelant) — même
 * geste que crTestLigne() dans RapportCompteResultatTest.php, dupliqué ici
 * sous un autre nom pour éviter toute collision de fonction globale entre
 * fichiers de tests Pest.
 */
function n1TestTransaction(Association $association, User $user, Compte $compte, string $date, bool $estDepense, float $montant): void
{
    $tx = $estDepense
        ? Transaction::factory()->asDepense()->create(['association_id' => $association->id, 'date' => $date, 'saisi_par' => $user->id])
        : Transaction::factory()->asRecette()->create(['association_id' => $association->id, 'date' => $date, 'saisi_par' => $user->id]);
    $tx->lignes()->forceDelete();

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => $montant,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

function n1XlsxSheet(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'crn1').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

/**
 * Colonnes du classeur (voir RapportExportController::xlsxCompteResultat) :
 * A Type | B Famille | C Compte | D N-1 | E N | F Budget | G Écart.
 * La ligne RÉSULTAT est repérée par son libellé en colonne C — jamais par sa
 * valeur, que plusieurs lignes peuvent partager.
 */
function n1XlsxCelluleResultat(Worksheet $sheet): mixed
{
    $rows = $sheet->toArray();
    foreach ($rows as $i => $r) {
        if (($r[2] ?? null) === 'RÉSULTAT') {
            return $sheet->getCell('D'.($i + 1))->getValue();
        }
    }

    return 'INTROUVABLE';
}

/**
 * Cellule N-1 de la ligne RÉSULTAT à l'écran : repérée par le style qui lui
 * est propre dans la vue (color:rgba(255,255,255,.6) n'apparaît qu'une fois
 * dans tout le fichier), jamais par sa valeur.
 */
function n1EcranCelluleResultat(string $html): ?string
{
    preg_match('/RÉSULTAT.*?rgba\(255,255,255,\.6\);">(.*?)<\/td>/s', $html, $m);

    return $m[1] ?? null;
}

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

it('aucune donnee N-1 : l ecran affiche le tiret et le xlsx laisse la cellule vide', function (): void {
    $compteCharge = Compte::factory()->numero('606')->create(['association_id' => $this->association->id, 'intitule' => 'Fournitures']);
    $compteProduit = Compte::factory()->numero('706')->create(['association_id' => $this->association->id, 'intitule' => 'Prestations']);

    // N seulement, aucune écriture en 2024 (exercice N-1) : les deux sections
    // n'ont donc aucune donnée N-1, pas seulement un net à zéro.
    n1TestTransaction($this->association, $this->user, $compteCharge, '2025-11-01', true, 250.00);
    n1TestTransaction($this->association, $this->user, $compteProduit, '2025-11-01', false, 800.00);

    $html = Livewire::test(RapportCompteResultat::class)->assertOk()->html();
    expect(n1EcranCelluleResultat($html))->toBe('&mdash;');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'compte-resultat',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $valeur = n1XlsxCelluleResultat(n1XlsxSheet($response));
    expect($valeur)->not->toBe('INTROUVABLE')
        ->and($valeur)->toBeNull();
});

it('N-1 a l equilibre : l ecran affiche 0,00 et le xlsx ecrit un vrai zero — c est le cas que le != 0 masquait', function (): void {
    $compteCharge = Compte::factory()->numero('616')->create(['association_id' => $this->association->id, 'intitule' => 'Frais bancaires']);
    $compteProduit = Compte::factory()->numero('716')->create(['association_id' => $this->association->id, 'intitule' => 'Adhésions']);

    // Charges N-1 = produits N-1 = 300 : résultat N-1 net à 0, mais chaque
    // section PORTE bien une donnée — ce n'est pas une absence.
    n1TestTransaction($this->association, $this->user, $compteCharge, '2024-11-15', true, 300.00);
    n1TestTransaction($this->association, $this->user, $compteProduit, '2024-11-15', false, 300.00);

    $html = Livewire::test(RapportCompteResultat::class)->assertOk()->html();
    expect(n1EcranCelluleResultat($html))->toBe('0,00 &euro;');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'compte-resultat',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $valeur = n1XlsxCelluleResultat(n1XlsxSheet($response));
    expect($valeur)->not->toBe('INTROUVABLE')
        ->and($valeur)->not->toBeNull()
        ->and($valeur)->toBeFloat()
        ->and($valeur)->toBe(0.0);
});

it('N-1 non nul : le montant s affiche normalement sur l ecran et dans le xlsx (non regression)', function (): void {
    $compteCharge = Compte::factory()->numero('616')->create(['association_id' => $this->association->id, 'intitule' => 'Frais bancaires']);
    $compteProduit = Compte::factory()->numero('716')->create(['association_id' => $this->association->id, 'intitule' => 'Adhésions']);

    // Produits N-1 500 - charges N-1 100 = résultat N-1 400, non nul.
    n1TestTransaction($this->association, $this->user, $compteCharge, '2024-11-15', true, 100.00);
    n1TestTransaction($this->association, $this->user, $compteProduit, '2024-11-15', false, 500.00);

    $html = Livewire::test(RapportCompteResultat::class)->assertOk()->html();
    expect(n1EcranCelluleResultat($html))->toBe('400,00 &euro;');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'compte-resultat',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $valeur = n1XlsxCelluleResultat(n1XlsxSheet($response));
    expect($valeur)->not->toBe('INTROUVABLE')
        ->and($valeur)->not->toBeNull()
        ->and($valeur)->toBeFloat()
        ->and($valeur)->toBe(400.0);
});
