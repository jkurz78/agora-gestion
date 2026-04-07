<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\FacturePdfController;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Register a temporary route for the controller (routes not yet added in Task 13)
    Route::middleware('web')
        ->get('/test/factures/{facture}/pdf', FacturePdfController::class)
        ->name('test.facture.pdf');
});

/**
 * Create a validated facture with one montant line, ready for PDF generation.
 */
function createValidatedFacture(): Facture
{
    $tiers = Tiers::factory()->pourRecettes()->create([
        'type' => 'entreprise',
        'entreprise' => 'Acme Corp',
        'adresse_ligne1' => '12 rue de la Paix',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $compteBancaire = CompteBancaire::factory()->create([
        'iban' => 'FR76 1234 5678 9012 3456 7890 123',
        'bic' => 'BNPAFRPP',
        'domiciliation' => 'BNP Paribas Paris',
    ]);

    Association::create([
        'nom' => 'Asso Test',
        'forme_juridique' => 'Association loi 1901',
        'adresse' => '1 rue du Test',
        'code_postal' => '69001',
        'ville' => 'Lyon',
        'email' => 'test@monasso.fr',
        'telephone' => '04 00 00 00 00',
        'siret' => '12345678901234',
        'facture_conditions_reglement' => 'Payable à réception',
        'facture_mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
        'facture_mentions_penalites' => 'Pénalités de retard : 3 fois le taux légal',
        'facture_compte_bancaire_id' => $compteBancaire->id,
    ]);

    $facture = Facture::create([
        'numero' => 'F-2025-0001',
        'date' => '2025-10-15',
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'compte_bancaire_id' => $compteBancaire->id,
        'conditions_reglement' => 'Payable à réception',
        'mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
        'montant_total' => 150.00,
        'exercice' => 2025,
        'saisi_par' => User::factory()->create()->id,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::Texte,
        'libelle' => 'Prestation de formation',
        'montant' => null,
        'ordre' => 1,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Session du 15/10/2025',
        'montant' => 150.00,
        'ordre' => 2,
    ]);

    return $facture;
}

it('generates a non-empty PDF string via FactureService::genererPdf', function () {
    $facture = createValidatedFacture();

    $service = app(FactureService::class);
    $pdfContent = $service->genererPdf($facture);

    expect($pdfContent)->toBeString()
        ->and(strlen($pdfContent))->toBeGreaterThan(100)
        ->and(str_starts_with($pdfContent, '%PDF'))->toBeTrue();
});

it('returns 200 with application/pdf content type via controller', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $facture = createValidatedFacture();

    $response = $this->get("/test/factures/{$facture->id}/pdf");

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});

it('includes Factur-X XML embedded in the PDF', function () {
    $facture = createValidatedFacture();

    $service = app(FactureService::class);
    $pdfContent = $service->genererPdf($facture);

    // The Factur-X library embeds factur-x.xml as an attachment in the PDF
    // We can verify by checking that the PDF contains the factur-x.xml reference
    expect($pdfContent)->toContain('factur-x.xml');
});
