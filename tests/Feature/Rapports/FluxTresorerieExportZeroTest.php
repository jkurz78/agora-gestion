<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée. La
 * ventilation mensuelle du flux de trésorerie porte les douze mois de
 * l'exercice quoi qu'il arrive : un mois sans la moindre recette n'est pas un
 * mois hors périmètre, c'est un mois réel dont les Recettes valent
 * exactement 0,00 — jamais une case vide.
 *
 * Ce test couvre les trois sites de xlsxFluxTresorerie() avec une seule
 * association neuve (aucun compte bancaire, aucune écriture antérieure) et
 * une unique dépense en octobre :
 * - le solde d'ouverture (E) : 0,00 réel (rien à reprendre), pas une absence ;
 * - la ligne « Octobre 2025 » : Recettes (B) à 0,00, le mois a bougé
 *   (Dépenses) sans la moindre recette ;
 * - la ligne TOTAL : total des recettes (B) à 0,00 sur tout l'exercice.
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

function lireClasseurFluxTresorerieZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'fluxzero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('le flux de tresorerie ecrit 0,00 pour un solde d ouverture et un mois sans la moindre recette, reels', function (): void {
    $compte512 = Compte::factory()->numero('512')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Banque',
    ]);

    // Seule transaction de l'association : une dépense en octobre 2025 dont
    // la jambe de trésorerie (classe 5) est le seul mouvement de tout
    // l'exercice. Aucun compte bancaire préexistant, aucune écriture avant
    // le 01/09/2025 : le solde d'ouverture est un vrai zéro comptable, pas
    // une donnée manquante.
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512->id,
        'debit' => 0,
        'credit' => 200.00,
        'montant' => 200.00,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'flux-tresorerie',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurFluxTresorerieZero($response);
    // formatData: false — sinon le format `#,##0.00` posé sur B2:E$row
    // renverrait des chaînes formatées pour la recherche de ligne ci-dessous.
    $rows = $sheet->toArray(null, true, false);

    // Colonnes : A (libellé/mois) | B Recettes | C Dépenses | D Solde (R-D)
    // | E Trésorerie cumulée
    $ligneOuvertureIndex = null;
    $ligneOctobreIndex = null;
    $ligneTotalIndex = null;
    foreach ($rows as $i => $r) {
        $premiereColonne = $r[0] ?? null;
        if ($premiereColonne === 'Solde ouverture') {
            $ligneOuvertureIndex = $i + 1;
        } elseif ($premiereColonne === 'Octobre 2025') {
            $ligneOctobreIndex = $i + 1;
        } elseif ($premiereColonne === 'TOTAL') {
            $ligneTotalIndex = $i + 1;
        }
    }

    expect($ligneOuvertureIndex)->not->toBeNull()
        ->and($ligneOctobreIndex)->not->toBeNull()
        ->and($ligneTotalIndex)->not->toBeNull();

    // Ligne « Solde ouverture » : Recettes/Dépenses/Solde (B, C, D) restent
    // VIDES — elles ne représentent rien sur cette ligne — mais la
    // Trésorerie cumulée (E) est un vrai 0,00 (association neuve, rien à
    // reprendre).
    expect($sheet->getCell('B'.$ligneOuvertureIndex)->getValue())->toBeNull();
    expect($sheet->getCell('C'.$ligneOuvertureIndex)->getValue())->toBeNull();
    expect($sheet->getCell('D'.$ligneOuvertureIndex)->getValue())->toBeNull();
    $soldeOuverture = $sheet->getCell('E'.$ligneOuvertureIndex)->getValue();
    expect($soldeOuverture)->not->toBeNull()
        ->and($soldeOuverture)->toBeFloat()
        ->and($soldeOuverture)->toBe(0.0);

    // Ligne « Octobre 2025 » : Recettes (B) = 0,00, un vrai zéro — le mois a
    // bougé (Dépenses = 200,00) sans la moindre recette.
    $recettesOctobre = $sheet->getCell('B'.$ligneOctobreIndex)->getValue();
    expect($recettesOctobre)->not->toBeNull()
        ->and($recettesOctobre)->toBeFloat()
        ->and($recettesOctobre)->toBe(0.0);
    $depensesOctobre = $sheet->getCell('C'.$ligneOctobreIndex)->getValue();
    expect($depensesOctobre)->not->toBeNull()
        ->and($depensesOctobre)->toBeFloat()
        ->and($depensesOctobre)->toBe(200.0);

    // Ligne TOTAL : total des recettes (B) = 0,00 sur tout l'exercice —
    // aucune recette nulle part, un vrai zéro, pas une absence.
    $totalRecettes = $sheet->getCell('B'.$ligneTotalIndex)->getValue();
    expect($totalRecettes)->not->toBeNull()
        ->and($totalRecettes)->toBeFloat()
        ->and($totalRecettes)->toBe(0.0);
});
