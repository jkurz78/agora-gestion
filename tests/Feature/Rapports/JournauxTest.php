<?php

declare(strict_types=1);

/**
 * Livre-journal : les écritures classées par journal puis par pièce.
 *
 * Ce que cet état apporte et que le grand livre ne montre pas : chaque pièce
 * d'un bloc, avec toutes ses lignes, débit et crédit face à face.
 */

use App\Enums\JournalComptable;
use App\Enums\SensVentilation;
use App\Enums\TypeTransaction;
use App\Livewire\RapportJournaux;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Rapports\JournauxBuilder;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->ecritures = app(EcritureGenerator::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

function creerCreanceJournal(object $contexte, string $nom = 'Client Journal'): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $contexte->association->id,
        'nom' => $nom,
    ]);

    $contexte->ecritures->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(12000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-05'),
        libelle: 'Créance journal',
    );

    return $tiers;
}

it('regroupe les écritures par journal puis par pièce', function (): void {
    creerCreanceJournal($this);

    $resultat = app(JournauxBuilder::class)->journaux('2025-09-01', '2026-08-31');

    $vente = collect($resultat['journaux'])->firstWhere('code', JournalComptable::Vente->value);

    expect($vente)->not->toBeNull()
        ->and($vente['libelle'])->toBe('Journal des ventes')
        ->and($vente['pieces'])->toHaveCount(1);

    // La pièce porte les DEUX lignes de l'écriture : c'est tout l'intérêt.
    $piece = $vente['pieces'][0];
    expect($piece['lignes'])->toHaveCount(2)
        ->and($piece['debit_centimes'])->toBe(12000)
        ->and($piece['credit_centimes'])->toBe(12000)
        ->and($piece['equilibree'])->toBeTrue()
        ->and(collect($piece['lignes'])->pluck('numero_compte')->sort()->values()->all())
        ->toBe(['411', '706']);
});

it('filtre sur une sélection de journaux', function (): void {
    creerCreanceJournal($this);

    $tous = app(JournauxBuilder::class)->journaux('2025-09-01', '2026-08-31');
    expect(count($tous['journaux']))->toBeGreaterThan(0);

    $achatsSeuls = app(JournauxBuilder::class)
        ->journaux('2025-09-01', '2026-08-31', [JournalComptable::Achat->value]);

    expect(collect($achatsSeuls['journaux'])->pluck('code')->all())
        ->not->toContain(JournalComptable::Vente->value);
});

it('fait figurer la pièce d à-nouveaux dans son journal', function (): void {
    // Dans la balance et le grand livre, l'AN est une ouverture ; ici c'est une
    // pièce du journal des à-nouveaux, elle doit s'afficher.
    $transaction = Transaction::query()->create([
        'association_id' => (int) $this->association->id,
        'type' => TypeTransaction::AN,
        'date' => '2025-09-01',
        'libelle' => 'AN ouverture',
        'montant_total' => '500.00',
        'saisi_par' => (int) $this->user->id,
        'journal' => JournalComptable::AN,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compte512X->id,
        'debit' => '500.00',
        'credit' => '0.00',
        'montant' => '0.00',
        'libelle' => 'AN banque',
    ]);

    $resultat = app(JournauxBuilder::class)->journaux('2025-09-01', '2026-08-31');
    $an = collect($resultat['journaux'])->firstWhere('code', JournalComptable::AN->value);

    expect($an)->not->toBeNull()
        ->and($an['libelle'])->toBe('Journal des à-nouveaux')
        ->and($an['pieces'])->toHaveCount(1)
        ->and($an['debit_centimes'])->toBe(50000);
});

it('signale une pièce déséquilibrée au lieu de la masquer', function (): void {
    $transaction = Transaction::query()->create([
        'association_id' => (int) $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-10',
        'libelle' => 'Écriture bancale',
        'montant_total' => '100.00',
        'saisi_par' => (int) $this->user->id,
        'journal' => JournalComptable::Od,
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => '0.00',
        'credit' => '100.00',
        'montant' => '0.00',
        'libelle' => 'Produit sans contrepartie',
    ]);

    $resultat = app(JournauxBuilder::class)->journaux('2025-09-01', '2026-08-31');
    $od = collect($resultat['journaux'])->firstWhere('code', JournalComptable::Od->value);

    expect($od['pieces'][0]['equilibree'])->toBeFalse()
        ->and($od['pieces'][0]['debit_centimes'])->toBe(0)
        ->and($od['pieces'][0]['credit_centimes'])->toBe(10000);
});

it('affiche l écran Journaux avec ses totaux', function (): void {
    creerCreanceJournal($this);

    Livewire::test(RapportJournaux::class)
        ->set('dateDebut', '2025-09-01')
        ->set('dateFin', '2026-08-31')
        ->assertOk()
        ->assertSee('Journal des ventes')
        ->assertSee('CLIENT JOURNAL')
        ->assertSee('411')
        ->assertSee('706')
        ->assertSee('TOTAL GÉNÉRAL', false);
});

it('rend la page hôte Journaux', function (): void {
    $this->get(route('rapports.journaux'))
        ->assertOk()
        ->assertSeeLivewire(RapportJournaux::class)
        ->assertSee('Journaux');
});

// ─────────────────────────────────────────────────────────────────────────────
// Exports. L'Excel des journaux est volontairement « à plat » : chaque ligne
// porte tout son contexte, pour rester exploitable après un tri ou un filtre.
// ─────────────────────────────────────────────────────────────────────────────

it('exporte les journaux en Excel avec une ligne complète par écriture', function (): void {
    creerCreanceJournal($this);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'journaux',
        'format' => 'xlsx',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'exercice' => 2025,
    ]));

    $response->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'journaux').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();

    $lignes = [];
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        $valeurs = [];
        foreach ($sheet->getRowIterator($row, $row) as $ligneFeuille) {
            foreach ($ligneFeuille->getCellIterator() as $cellule) {
                $valeurs[] = $cellule->getValue();
            }
        }
        $lignes[] = $valeurs;
    }
    @unlink($tmp);

    $plat = collect($lignes);

    // En-têtes dénormalisés attendus.
    $entetes = $plat->first(fn (array $l): bool => in_array('Journal', $l, true));
    expect($entetes)->toContain('Journal', 'Date', 'Pièce', 'Libellé', 'Compte', 'Règlement', 'Lettrage', 'Débit', 'Crédit');

    // Chaque ligne d'écriture porte à la fois le journal, la pièce et le compte.
    $ligne411 = $plat->first(fn (array $l): bool => in_array('411', $l, true));
    expect($ligne411)->not->toBeNull()
        ->and($ligne411)->toContain('Journal des ventes')
        ->and($ligne411)->toContain('Créance journal');

    // Le total est posé par journal, pas par pièce.
    $totalJournal = $plat->first(fn (array $l): bool => in_array('TOTAL Journal des ventes', $l, true));
    expect($totalJournal)->not->toBeNull()
        ->and($plat->first(fn (array $l): bool => in_array('TOTAL GÉNÉRAL', $l, true)))->not->toBeNull();
});

it('exporte les journaux en PDF', function (): void {
    creerCreanceJournal($this);

    $this->get(route('rapports.export', [
        'rapport' => 'journaux',
        'format' => 'pdf',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'exercice' => 2025,
    ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exporte le grand livre en Excel et en PDF', function (): void {
    creerCreanceJournal($this);

    $this->get(route('rapports.export', [
        'rapport' => 'grand-livre',
        'format' => 'xlsx',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'comptes' => '411',
        'exercice' => 2025,
    ]))->assertOk();

    $this->get(route('rapports.export', [
        'rapport' => 'grand-livre',
        'format' => 'pdf',
        'du' => '2025-09-01',
        'au' => '2026-08-31',
        'comptes' => '411',
        'exercice' => 2025,
    ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
