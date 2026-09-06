<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée.
 *
 * Une ligne réglée peut être éclatée en plusieurs affectations d'opération
 * (VentilationFinanciereService::q2()) : rien n'empêche l'une d'elles de
 * porter un montant exactement nul — une part gratuite d'une prestation par
 * ailleurs payante, par exemple. `enrich()` signe alors ce montant via
 * `(float) ($data['Montant'] ?? 0)`, un vrai 0.0, jamais un `null` : il doit
 * s'écrire « 0,00 », pas rester une case vide, indiscernable d'une ligne qui
 * n'existe pas.
 */

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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

function lireClasseurAnalyseZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'analysezero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('l export analyse financiere ecrit 0,00 pour une affectation d operation a montant reellement nul', function (): void {
    $compteBancaire = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $compteVentilation = Compte::factory()->numero('706')->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $operationGratuite = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Atelier decouverte gratuit',
    ]);

    // Recette réelle de 100 € (invariant partie double : credit > 0), mais
    // intégralement ventilée sur une opération à titre gratuit — l'affectation
    // qui la porte a un montant exactement nul, une part réelle de zéro, pas
    // une absence de ventilation.
    $tx = Transaction::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compteBancaire->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Inscription atelier gratuit',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 100.00,
        'compte_id' => $compteVentilation->id,
        'debit' => 0.0,
        'credit' => 100.00,
    ]);
    $ligne->affectations()->create([
        'operation_id' => $operationGratuite->id,
        'montant' => 0.00,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'analyse-financier',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurAnalyseZero($response);
    $rows = $sheet->toArray(null, true, false);

    // En-têtes en ligne 1 : Date, N° pièce, Référence, Mode paiement,
    // Libellé, Tiers, Type tiers, Compte comptable, Famille, Type,
    // Compte bancaire, Opération, Type opération, Séance n°, Montant, ...
    $headers = $rows[0];
    $colLibelle = array_search('Libellé', $headers, true);
    $colMontant = array_search('Montant', $headers, true);
    expect($colLibelle)->not->toBeFalse()
        ->and($colMontant)->not->toBeFalse();

    // Identification par le libellé de la transaction, jamais par la valeur :
    // une collision avec un TOTAL ou une autre ligne à 0,00 serait sinon
    // possible.
    $ligneIndex = null;
    foreach ($rows as $i => $r) {
        if (($r[$colLibelle] ?? null) === 'Inscription atelier gratuit') {
            $ligneIndex = $i + 1;
            break;
        }
    }
    expect($ligneIndex)->not->toBeNull();

    $colonneMontant = Coordinate::stringFromColumnIndex($colMontant + 1);
    $montant = $sheet->getCell($colonneMontant.$ligneIndex)->getValue();
    expect($montant)->not->toBeNull()
        ->and($montant)->toBeFloat()
        ->and($montant)->toBe(0.0);
});
