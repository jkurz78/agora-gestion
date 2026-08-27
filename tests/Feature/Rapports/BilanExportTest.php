<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

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

it('exporte le bilan comptable au format PDF', function (): void {
    $this->get(route('rapports.export', ['rapport' => 'bilan', 'format' => 'pdf', 'exercice' => 2025]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('ne rend aucune trace de N moins 1 quand n1 vaut zéro', function (): void {
    $html = view('pdf.rapport-bilan', donneesVueBilanPdf(false))->render();

    expect($html)
        ->toContain('Bilan provisoire avant clôture')
        ->toContain('Charges constatées d’avance')
        ->toContain('Provisions pour risques et charges')
        ->toContain('Produits constatés d’avance')
        ->not->toContain('2024-2025')
        ->not->toContain('N-1');
});

it('affiche l avertissement lorsque le bilan est déséquilibré', function (): void {
    $data = donneesVueBilanPdf(true);
    $data['bilan']['ecart_actif_passif']['n_centimes'] = 1000;

    expect(view('pdf.rapport-bilan', $data)->render())
        ->toContain('Écart actif/passif')
        ->toContain('10,00 €');
});
