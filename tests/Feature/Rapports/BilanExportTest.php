<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use Smalot\PdfParser\Parser;

beforeEach(function (): void {
    $this->associationBilanPdf = Association::factory()->create();
    $this->utilisateurBilanPdf = User::factory()->create();
    $this->utilisateurBilanPdf->associations()->attach($this->associationBilanPdf->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->associationBilanPdf);
    session(['current_association_id' => $this->associationBilanPdf->id]);
    $this->actingAs($this->utilisateurBilanPdf);
});

function donneesVueBilanPdf(bool $compareN1): array
{
    return [
        'title' => 'Bilan comptable',
        'subtitle' => 'Exercice 2025-2026',
        'association' => null,
        'headerLogoBase64' => null,
        'headerLogoMime' => 'image/png',
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
        'compareN1' => $compareN1,
        'bilan' => [
            'label_n' => '2025-2026',
            'label_n_1' => '2024-2025',
            'provisoire' => true,
            'actif' => [[
                'libelle' => 'Charges constatées d’avance',
                'brut_n_centimes' => 12000,
                'amortissements_provisions_n_centimes' => 0,
                'net_n_centimes' => 12000,
                'net_n_1_centimes' => 6000,
            ]],
            'passif' => [
                ['libelle' => 'Provisions pour risques et charges', 'montant_n_centimes' => 5000, 'montant_n_1_centimes' => 3000],
                ['libelle' => 'Produits constatés d’avance', 'montant_n_centimes' => 7000, 'montant_n_1_centimes' => 3000],
            ],
            'totaux' => [
                'actif_n_brut_centimes' => 12000,
                'actif_n_amortissements_provisions_centimes' => 0,
                'actif_n_net_centimes' => 12000,
                'actif_n_1_net_centimes' => 6000,
                'passif_n_centimes' => 12000,
                'passif_n_1_centimes' => 6000,
            ],
            'ecart_actif_passif' => ['n_centimes' => 0, 'n_1_centimes' => 0],
        ],
    ];
}

function creerCompteBilanPdf(string $numero, string $intitule): Compte
{
    return Compte::query()->firstOrCreate(['numero_pcg' => $numero], [
        'association_id' => TenantContext::currentId(),
        'intitule' => $intitule,
        'classe' => (int) $numero[0],
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function montantBilanPdf(int $centimes): string
{
    return intdiv($centimes, 100).'.'.str_pad((string) ($centimes % 100), 2, '0', STR_PAD_LEFT);
}

function enregistrerEcritureBilanPdf(Compte $compte, Compte $contrepartie, int $montantCentimes, bool $crediterCompte, string $date): void
{
    $transaction = Transaction::query()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Virement,
        'date' => $date,
        'libelle' => 'Fixture intégration PDF bilan',
        'montant_total' => montantBilanPdf($montantCentimes),
        'saisi_par' => (int) test()->utilisateurBilanPdf->id,
        'equilibree' => true,
    ]);
    $montant = montantBilanPdf($montantCentimes);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compte->id,
        'debit' => $crediterCompte ? '0.00' : $montant,
        'credit' => $crediterCompte ? $montant : '0.00',
        'montant' => '0.00',
        'libelle' => 'Ligne bilan',
    ]);
    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $contrepartie->id,
        'debit' => $crediterCompte ? $montant : '0.00',
        'credit' => $crediterCompte ? '0.00' : $montant,
        'montant' => '0.00',
        'libelle' => 'Contrepartie équilibrée',
    ]);
}

function fixtureIntegrationBilanPdf(): void
{
    Exercice::query()->create([
        'association_id' => TenantContext::currentId(),
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    $contrepartie = creerCompteBilanPdf('580', 'Virements internes');
    $cca = creerCompteBilanPdf('486', 'Charges constatées d’avance');
    $provisions = creerCompteBilanPdf('1511', 'Provisions pour risques');
    $pca = creerCompteBilanPdf('487', 'Produits constatés d’avance');

    enregistrerEcritureBilanPdf($cca, $contrepartie, 12345, false, '2025-10-15');
    enregistrerEcritureBilanPdf($provisions, $contrepartie, 6789, true, '2025-10-15');
    enregistrerEcritureBilanPdf($pca, $contrepartie, 4567, true, '2025-10-15');
    enregistrerEcritureBilanPdf($cca, $contrepartie, 1111, false, '2024-10-15');
    enregistrerEcritureBilanPdf($provisions, $contrepartie, 2222, true, '2024-10-15');
    enregistrerEcritureBilanPdf($pca, $contrepartie, 3333, true, '2024-10-15');
}

function textePdfBilan(TestResponse $response): string
{
    return (new Parser)->parseContent($response->getContent())->getText();
}

it('exporte les vraies rubriques du bilan en PDF sans N moins 1 quand n1 vaut zéro', function (): void {
    fixtureIntegrationBilanPdf();

    $response = $this->get(route('rapports.export', [
        'rapport' => 'bilan',
        'format' => 'pdf',
        'exercice' => 2025,
        'n1' => '0',
    ]));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(textePdfBilan($response))
        ->toContain('Bilan comptable')
        ->toContain('Bilan provisoire avant clôture')
        ->toContain('Charges constatées d’avance')
        ->toContain('Provisions pour risques et charges')
        ->toContain('Produits constatés d’avance')
        ->toContain('134,56 €')
        ->toContain('90,11 €')
        ->toContain('79,00 €')
        ->not->toContain('2024-2025')
        ->not->toContain('N-1');
});

it('affiche la colonne N moins 1 dans le PDF quand n1 est omis', function (): void {
    fixtureIntegrationBilanPdf();

    $response = $this->get(route('rapports.export', [
        'rapport' => 'bilan',
        'format' => 'pdf',
        'exercice' => 2025,
    ]));

    $response->assertOk();

    expect(textePdfBilan($response))
        ->toContain('2024-2025')
        ->toContain('11,11 €');
});

it('affiche l avertissement lorsque le bilan est déséquilibré', function (): void {
    $data = donneesVueBilanPdf(true);
    $data['bilan']['ecart_actif_passif']['n_centimes'] = 1000;

    expect(view('pdf.rapport-bilan', $data)->render())
        ->toContain('Écart actif/passif')
        ->toContain('10,00 €');
});
