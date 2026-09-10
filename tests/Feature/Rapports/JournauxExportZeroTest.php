<?php

declare(strict_types=1);

/**
 * Les journaux présentent chaque écriture en colonnes débit/crédit : une ligne
 * est au débit OU au crédit, jamais les deux. Le côté inutilisé reste VIDE —
 * décision du propriétaire (2026-09-10) après lecture d'un export réel, où les
 * 0,00 encombraient plus qu'ils n'éclairaient.
 *
 * Ce test épingle cette décision : reposer `strictNullComparison: true` sur
 * l'un des trois sites des journaux (ligne, total du journal, total général)
 * le fait tomber. GrandLivreExportZeroTest détaille le raisonnement.
 *
 * Scénario : une pièce déséquilibrée volontairement, toute au crédit — celle
 * de JournauxTest.php — dont le débit nul se retrouve aux deux totaux.
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

it('les journaux laissent vide le cote debit inutilise, a la ligne comme aux deux totaux', function (): void {
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

    // La ligne porte bien son montant : 100,00 au crédit. Sans cette
    // vérification, un export vide passerait toutes les assertions ci-dessous.
    $creditLigne = $sheet->getCell('K'.$ligneEcritureIndex)->getValue();
    expect($creditLigne)->not->toBeNull()
        ->and((float) $creditLigne)->toBe(100.0);

    // Débit (J) reste VIDE — la pièce est toute au crédit, le côté débit est
    // celui qu'on n'utilise pas. Idem aux deux totaux.
    expect($sheet->getCell('J'.$ligneEcritureIndex)->getValue())->toBeNull();
    expect($sheet->getCell('J'.$ligneTotalJournalIndex)->getValue())->toBeNull();
    expect($sheet->getCell('J'.$ligneTotalGeneralIndex)->getValue())->toBeNull();

    // Les colonnes non monétaires du TOTAL GÉNÉRAL (Date à Lettrage) restent
    // vides, comme elles l'ont toujours été.
    foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
        expect($sheet->getCell($col.$ligneTotalGeneralIndex)->getValue())->toBeNull();
    }
});
