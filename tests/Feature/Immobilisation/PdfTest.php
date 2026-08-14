<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

it('produit un PDF de la fiche', function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    $this->actingAs(User::factory()->create())
        ->get(route('immobilisations.pdf', $immo))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('suit le patron PDF maison : logo/identité association en en-tête et pied de page commun', function (): void {
    // Conformité statique — un PDF se teste mal au-delà du MIME type (cf. test
    // ci-dessus) : on vérifie ici que le contrôleur et la vue suivent le même
    // patron que App\Http\Controllers\RapprochementPdfController, plutôt que
    // de tenter de parser le binaire PDF généré.
    $controller = file_get_contents(app_path('Http/Controllers/ImmobilisationPdfController.php'));

    expect($controller)
        ->toContain('CurrentAssociation::get()')
        ->and($controller)->toContain('brandingLogoFullPath()')
        ->and($controller)->toContain('PdfFooterRenderer::render(');

    $view = file_get_contents(resource_path('views/pdf/immobilisation.blade.php'));

    expect($view)
        ->toContain("@include('pdf.partials.footer-logos')")
        ->and($view)->toContain('$association->nom')
        ->and($view)->toContain('logoBase64');
});
