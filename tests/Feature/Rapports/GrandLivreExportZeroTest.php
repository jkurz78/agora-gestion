<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée. En partie
 * double, une ligne est au débit OU au crédit : l'autre colonne vaut zéro,
 * et ce zéro EXISTE (c'est la contrepartie), il ne doit jamais disparaître.
 *
 * Ce test couvre les trois sites du grand livre avec un seul compte à
 * mouvement débit-seul (jamais crédité) :
 * - la ligne « Solde ouverture » : le Solde doit porter 0,00 (compte neuf,
 *   aucun mouvement antérieur), et ses colonnes Débit/Crédit doivent elles
 *   rester VIDES (elles ne représentent rien pour une ligne de solde) ;
 * - la ligne d'écriture : le Crédit doit porter 0,00, la contrepartie ;
 * - la ligne TOTAL du compte : le Mouvement crédit doit porter 0,00.
 */

use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('current_association_id');
});

function lireClasseurGrandLivreZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'glzero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('le grand livre ecrit 0,00 pour une contrepartie et un solde d ouverture reels, mais laisse vides les colonnes Debit/Credit de la ligne de solde', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Achats non stockés',
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-05',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 90.00,
        'credit' => 0,
        'montant' => 90.00,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'grand-livre',
        'format' => 'xlsx',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'comptes' => '606',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurGrandLivreZero($response);
    // formatData: false — sinon le format `#,##0.00` posé sur les colonnes de
    // montant renverrait des chaînes formatées et casserait la recherche
    // `=== 90.0` ci-dessous ; getCell()->getValue() plus bas n'est de toute
    // façon jamais affecté par le format d'affichage.
    $rows = $sheet->toArray(null, true, false);

    // Colonnes : A Compte | B Intitulé | C Tiers | D Date | E Journal | F Pièce
    // | G Libellé | H Règlement | I Lettrage | J Débit | K Crédit | L Solde

    // La ligne TOTAL porte elle aussi 90.0 en colonne Débit (le mouvement du
    // compte) : chercher la ligne d'écriture par sa seule valeur de débit la
    // confondrait avec la ligne TOTAL. On distingue donc d'abord par le
    // libellé (colonne G) — 'Solde ouverture' et 'TOTAL' sont des libellés
    // fabriqués par le contrôleur, jamais ceux d'une vraie transaction.
    $ligneSoldeOuvertureIndex = null;
    $ligneEcritureIndex = null;
    $ligneTotalIndex = null;
    foreach ($rows as $i => $r) {
        $libelle = $r[6] ?? null;
        if ($libelle === 'Solde ouverture') {
            $ligneSoldeOuvertureIndex = $i + 1;
        } elseif ($libelle === 'TOTAL') {
            $ligneTotalIndex = $i + 1;
        } elseif (($r[9] ?? null) === 90.0) {
            $ligneEcritureIndex = $i + 1;
        }
    }

    expect($ligneSoldeOuvertureIndex)->not->toBeNull()
        ->and($ligneEcritureIndex)->not->toBeNull()
        ->and($ligneTotalIndex)->not->toBeNull();

    // Ligne « Solde ouverture » : Débit (J) et Crédit (K) restent VIDES —
    // elles ne représentent rien sur cette ligne — mais Solde (L) est un vrai
    // 0,00 (compte neuf sans mouvement antérieur au 01/09/2025).
    expect($sheet->getCell('J'.$ligneSoldeOuvertureIndex)->getValue())->toBeNull();
    expect($sheet->getCell('K'.$ligneSoldeOuvertureIndex)->getValue())->toBeNull();
    $soldeOuverture = $sheet->getCell('L'.$ligneSoldeOuvertureIndex)->getValue();
    expect($soldeOuverture)->not->toBeNull()
        ->and($soldeOuverture)->toBeFloat()
        ->and($soldeOuverture)->toBe(0.0);

    // Ligne d'écriture : Débit = 90,00 (déjà vérifié par la recherche), Crédit
    // (K) = 0,00 — la contrepartie de la partie double, qui EXISTE.
    $creditEcriture = $sheet->getCell('K'.$ligneEcritureIndex)->getValue();
    expect($creditEcriture)->not->toBeNull()
        ->and($creditEcriture)->toBeFloat()
        ->and($creditEcriture)->toBe(0.0);

    // Ligne TOTAL du compte : Mouvement crédit (K) = 0,00.
    $creditTotal = $sheet->getCell('K'.$ligneTotalIndex)->getValue();
    expect($creditTotal)->not->toBeNull()
        ->and($creditTotal)->toBeFloat()
        ->and($creditTotal)->toBe(0.0);
});
