<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée. Un bien déjà
 * totalement amorti avant l'exercice courant porte une Dotation exercice
 * FABRIQUÉE à 0 (cumul théorique inchangé d'un exercice à l'autre, voir
 * PlanAmortissementCalculator::cumulTheoriqueCentimes()) — ce zéro doit
 * s'écrire « 0,00 », jamais rester une case vide, à la ligne du bien comme au
 * TOTAL du livre.
 */

use App\Models\Association;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Transaction;
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

function lireClasseurImmobilisationsZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'immozero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('le livre des immobilisations ecrit 0 pour une dotation reellement nulle sur l exercice, a la ligne du bien comme au TOTAL', function (): void {
    $compteImmo = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '2183',
        'classe' => 2,
        'intitule' => 'Matériel de bureau',
    ]);
    $compteAmort = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '28183',
        'classe' => 2,
        'intitule' => 'Amortissement matériel de bureau',
    ]);
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2020-01-15',
        'saisi_par' => $this->user->id,
    ]);

    // Bien mis en service en janvier 2020 sur 12 mois : entièrement amorti
    // dès l'exercice 2020 (fini janvier 2021). Sur l'exercice 2025
    // (01/09/2025-31/08/2026), les mois écoulés sont plafonnés à la durée
    // pour l'exercice courant COMME pour le précédent (2024) : le cumul
    // théorique ne bouge plus d'un exercice à l'autre, donc la dotation 2025
    // est un zéro FABRIQUÉ, pas une absence de calcul.
    Immobilisation::factory()->create([
        'association_id' => $this->association->id,
        'numero' => 'IM00001',
        'libelle' => 'Photocopieur entierement amorti',
        'compte_id' => $compteImmo->id,
        'compte_amortissement_id' => $compteAmort->id,
        'montant_acquisition' => '1200.00',
        'date_mise_en_service' => '2020-01-15',
        'duree_mois' => 12,
        'transaction_id' => $tx->id,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurImmobilisationsZero($response);
    $rows = $sheet->toArray(null, true, false);

    // Colonnes : A N° | B Libellé | C Qté | D Compte | E Acquisition
    // | F Mise en service | G Durée | H Valeur brute | I Dotation exercice
    // | J Cumul amortissements | K Valeur nette
    $ligneDetailIndex = null;
    $ligneTotalIndex = null;
    foreach ($rows as $i => $r) {
        if (($r[1] ?? null) === 'Photocopieur entierement amorti') {
            $ligneDetailIndex = $i + 1;
        } elseif (($r[0] ?? null) === 'TOTAL') {
            $ligneTotalIndex = $i + 1;
        }
    }

    expect($ligneDetailIndex)->not->toBeNull()
        ->and($ligneTotalIndex)->not->toBeNull();

    // Ligne du bien : Dotation exercice (I) = 0 — un vrai zéro comptable
    // (bien entièrement amorti l'exercice précédent déjà), jamais un vide.
    // La division centimes/100 du contrôleur (sans passer par euros(), non
    // typée `float`) peut rendre un entier PHP exact quand le résultat est
    // divisible par 100 — 0/100 en est un — donc le type brut n'est pas
    // fiable ici ; seule l'absence-vs-présence de la cellule compte pour
    // cette régle, d'où la comparaison numérique après coup plutôt qu'un
    // ->toBeFloat() qui supposerait un typage que la source ne garantit pas.
    $dotationDetail = $sheet->getCell('I'.$ligneDetailIndex)->getValue();
    expect($dotationDetail)->not->toBeNull();
    expect((float) $dotationDetail)->toBe(0.0);

    // Ligne TOTAL : Dotation exercice (I) = 0 — seul bien du registre, même
    // zéro qu'au détail.
    $dotationTotal = $sheet->getCell('I'.$ligneTotalIndex)->getValue();
    expect($dotationTotal)->not->toBeNull();
    expect((float) $dotationTotal)->toBe(0.0);

    // Le pendant : sur la ligne TOTAL, les colonnes non monétaires (B à G)
    // n'ont jamais été posées et doivent rester vides.
    foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $col) {
        expect($sheet->getCell($col.$ligneTotalIndex)->getValue())->toBeNull();
    }
});
