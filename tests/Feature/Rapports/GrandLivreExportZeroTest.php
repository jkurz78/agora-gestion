<?php

declare(strict_types=1);

/**
 * Le grand livre, les journaux et la balance présentent leurs montants en
 * colonnes débit/crédit : une écriture est au débit OU au crédit, jamais les
 * deux. Le côté inutilisé reste VIDE. C'est la convention de lecture d'un
 * grand livre, et c'est une décision du propriétaire (2026-09-10), prise après
 * lecture d'un export réel où les 0,00 encombraient plus qu'ils n'éclairaient.
 *
 * Ces trois rapports ont donc retrouvé, en entier, leur présentation d'avant
 * la 5.3.5 — y compris le Solde nul d'un compte neuf, qui reste vide lui aussi.
 * Les autres exports (compte de résultat, flux de trésorerie, immobilisations,
 * analyse) écrivent au contraire leurs zéros : là, un zéro est une grandeur
 * métier — un mois sans recette, un bien totalement amorti — et non le côté
 * vide d'une présentation débit/crédit.
 *
 * Ce test épingle la décision sur les trois sites du grand livre (solde
 * d'ouverture, écriture, total du compte). `Worksheet::fromArray()` compare
 * chaque valeur à sa sentinelle `null` avec un `!=` lâche tant que
 * `strictNullComparison` vaut `false` — `0.0 != null` rend `false`, donc le
 * zéro n'est pas posé. Reposer le drapeau sur l'un de ces sites fait tomber
 * ce test.
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

it('le grand livre laisse vides le cote debit/credit inutilise et le solde nul, a l ouverture, a l ecriture et au total', function (): void {
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

    // Ligne « Solde ouverture » : rien n'y est écrit. Débit et Crédit n'y ont
    // aucun sens, et le Solde nul d'un compte neuf reste vide lui aussi — le
    // rapport entier a retrouvé sa présentation d'avant la 5.3.5.
    foreach (['J', 'K', 'L'] as $col) {
        expect($sheet->getCell($col.$ligneSoldeOuvertureIndex)->getValue())->toBeNull();
    }

    // Ligne d'écriture : Débit = 90,00 (la recherche ci-dessus le prouve),
    // Crédit reste VIDE — le côté inutilisé d'une écriture au débit.
    expect($sheet->getCell('K'.$ligneEcritureIndex)->getValue())->toBeNull();

    // Ligne TOTAL du compte : le Mouvement crédit, nul, reste vide.
    expect($sheet->getCell('K'.$ligneTotalIndex)->getValue())->toBeNull();
});
