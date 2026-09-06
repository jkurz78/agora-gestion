<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée. En partie
 * double, une pièce déséquilibrée signalée par JournauxBuilder porte un
 * débit ou un crédit à zéro — un zéro RÉEL, jamais une absence — et ce zéro
 * doit remonter jusqu'aux trois totaux (ligne, journal, général).
 *
 * Ce test réutilise le scénario de pièce déséquilibrée déjà éprouvé dans
 * JournauxTest.php (« signale une pièce déséquilibrée au lieu de la
 * masquer ») : une seule écriture, tout en crédit, débit_centimes = 0 partout
 * puisque c'est la seule transaction de l'exercice.
 */

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
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

function lireClasseurJournauxZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'jrnzero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('les journaux ecrivent 0,00 pour un debit reellement nul, a la ligne comme aux deux totaux, et laissent vides les colonnes non montaires du TOTAL', function (): void {
    $compte706 = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Cotisations',
    ]);

    // Pièce déséquilibrée volontairement (une seule ligne, tout en crédit) :
    // même fixture que JournauxTest.php. Seule transaction de l'exercice,
    // donc son débit_centimes = 0 se retrouve aussi au TOTAL du journal et au
    // TOTAL GÉNÉRAL.
    $transaction = Transaction::query()->create([
        'association_id' => (int) $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-10',
        'libelle' => 'Produit sans contrepartie',
        'montant_total' => '100.00',
        'saisi_par' => (int) $this->user->id,
        'journal' => JournalComptable::Od,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compte706->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => '0.00',
        'libelle' => 'Produit sans contrepartie',
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'journaux',
        'format' => 'xlsx',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurJournauxZero($response);
    // formatData: false — le format `#,##0.00` posé sur les colonnes Débit/
    // Crédit renverrait sinon des chaînes formatées pour la recherche
    // ci-dessous ; getCell()->getValue() plus bas n'est jamais affecté par
    // le format d'affichage, quel que soit ce réglage.
    $rows = $sheet->toArray(null, true, false);

    // Colonnes : A Journal | B Date | C Pièce | D Libellé | E Compte | F Intitulé
    // | G Tiers | H Règlement | I Lettrage | J Débit | K Crédit
    $ligneEcritureIndex = null;
    $ligneTotalJournalIndex = null;
    $ligneTotalGeneralIndex = null;
    foreach ($rows as $i => $r) {
        $premiereColonne = $r[0] ?? null;
        if ($premiereColonne === 'TOTAL GÉNÉRAL') {
            $ligneTotalGeneralIndex = $i + 1;
        } elseif (is_string($premiereColonne) && str_starts_with($premiereColonne, 'TOTAL ')) {
            $ligneTotalJournalIndex = $i + 1;
        } elseif (($r[4] ?? null) === '706') {
            // Colonne E = Compte.
            $ligneEcritureIndex = $i + 1;
        }
    }

    expect($ligneEcritureIndex)->not->toBeNull()
        ->and($ligneTotalJournalIndex)->not->toBeNull()
        ->and($ligneTotalGeneralIndex)->not->toBeNull();

    // Ligne d'écriture : Débit (J) = 0,00, un vrai zéro puisque la pièce est
    // réellement déséquilibrée côté crédit.
    $debitLigne = $sheet->getCell('J'.$ligneEcritureIndex)->getValue();
    expect($debitLigne)->not->toBeNull()
        ->and($debitLigne)->toBeFloat()
        ->and($debitLigne)->toBe(0.0);

    // TOTAL du journal des OD : Débit (J) = 0,00.
    $debitTotalJournal = $sheet->getCell('J'.$ligneTotalJournalIndex)->getValue();
    expect($debitTotalJournal)->not->toBeNull()
        ->and($debitTotalJournal)->toBeFloat()
        ->and($debitTotalJournal)->toBe(0.0);

    // TOTAL GÉNÉRAL : Débit (J) = 0,00 — seule transaction de l'exercice.
    $debitTotalGeneral = $sheet->getCell('J'.$ligneTotalGeneralIndex)->getValue();
    expect($debitTotalGeneral)->not->toBeNull()
        ->and($debitTotalGeneral)->toBeFloat()
        ->and($debitTotalGeneral)->toBe(0.0);

    // Le pendant : sur la ligne TOTAL GÉNÉRAL, les colonnes non monétaires
    // (Date à Lettrage) n'ont jamais été posées et doivent rester vides —
    // le drapeau ne doit pas se mettre à écrire des '0' ou chaînes vides à
    // leur place.
    foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
        expect($sheet->getCell($col.$ligneTotalGeneralIndex)->getValue())->toBeNull();
    }
});
