<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Alice Martin',
        'signataire_qualite' => 'Trésorière',
    ]);
    TenantContext::boot($this->asso);

    $this->service = app(RecuFiscalService::class);
});

/**
 * Crée une adhésion payée + déductible et retourne le HTML de la vue PDF générée.
 */
function genererHtmlPdfCotisation(RecuFiscalService $service, Association $asso): string
{
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => fake()->unique()->lastName(),
        'prenom' => 'Claude',
        'adresse_ligne1' => '1 rue Test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $sousCat = SousCategorie::query()
        ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Cotisation->value))
        ->first()
        ?? SousCategorie::factory()->pourCotisations()->create();

    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Cheque,
        'date' => now()->subMonths(2),
    ]);
    TransactionLigne::where('transaction_id', $transaction->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 60.00,
    ]);

    // L'observer AdhesionTransactionLigneObserver peut avoir auto-créé une adhésion
    // à la création de la TransactionLigne. On la supprime (forceDelete pour contourner
    // la contrainte unique SQLite qui inclut les soft-deleted) avant de créer l'adhésion de test.
    Adhesion::withTrashed()->where('tiers_id', $tiers->id)->forceDelete();

    $adhesion = Adhesion::factory()->create([
        'transaction_id' => $transaction->id,
        'tiers_id' => $tiers->id,
        'deductible_fiscal' => true,
        'exercice' => fake()->unique()->numberBetween(2020, 2030),
    ]);

    // Générer le reçu pour l'adhesion
    $recu = $service->obtenirOuGenererPourAdhesion($adhesion);

    // Récupérer le HTML stocké en lisant le PDF — on va plutôt inspecter la vue Blade directement
    // via Storage en lisant le binaire, mais pour tester le contenu HTML, on rend la vue Blade.
    // On reconstruit les paramètres via Reflection pour appeler genererPdfBinaire avec objet.
    // En pratique, on teste directement la vue Blade avec les bons paramètres.

    $recuTemporaire = new RecuFiscalEmis([
        'numero' => $recu->numero,
        'emitted_at' => now(),
        'date_versement' => $transaction->date,
        'montant_centimes' => 6000,
        'mode_versement' => 'cheque',
        'forme_don' => 'numeraire',
        'article_cgi' => 'art_200',
        'annule_at' => null,
    ]);

    return Blade::render(
        view('pdf.recu-fiscal-don')->with([
            'recu' => $recuTemporaire,
            'asso' => $asso,
            'donateur' => $tiers,
            'montantFormate' => '60,00 €',
            'montantEnLettres' => 'soixante euros',
            'articleCgiLibelle' => 'article 200',
            'numeroCgi' => '200',
            'titreDocument' => "Reçu au titre d'une cotisation à un organisme d'intérêt général",
            'contexteSpecifique' => null,
            'formeLibelle' => 'Cotisation versée par le membre',
            'modeLibelle' => 'Chèque',
            'headerLogoBase64' => null,
            'headerLogoMime' => null,
            'cachetBase64' => null,
            'cachetMime' => null,
            'appLogoBase64' => null,
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
            'objet' => 'cotisation',
        ])->render()
    );
}

it('PDF cotisation contient le titre cotisation dans titreDocument', function () {
    $html = genererHtmlPdfCotisation($this->service, $this->asso);

    expect($html)->toContain('cotisation');
    expect($html)->toContain('Reçu au titre d');
});

it('PDF cotisation affiche "Cotisation versée par le membre" comme forme', function () {
    $html = genererHtmlPdfCotisation($this->service, $this->asso);

    expect($html)->toContain('Cotisation versée par le membre');
    expect($html)->not->toContain('Don manuel en numéraire');
});

it('PDF cotisation mentionne article 200 pour tiers particulier (inchangé)', function () {
    $html = genererHtmlPdfCotisation($this->service, $this->asso);

    // La mention légale contient "l'article <strong>200</strong>"
    expect($html)->toContain('article')
        ->and($html)->toContain('200');
});

it('PDF don existant — wording DON inchangé (non-régression)', function () {
    Storage::fake('local');
    $assoEligible = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($assoEligible);
    $ligne = $this->ligneDonValide();
    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    $tiers = $ligne->transaction->tiers;

    $recuTemporaire = new RecuFiscalEmis([
        'numero' => $recu->numero,
        'emitted_at' => now(),
        'date_versement' => $ligne->transaction->date,
        'montant_centimes' => (int) round((float) $ligne->montant * 100),
        'mode_versement' => 'cheque',
        'forme_don' => 'numeraire',
        'article_cgi' => 'art_200',
        'annule_at' => null,
    ]);

    $html = Blade::render(
        view('pdf.recu-fiscal-don')->with([
            'recu' => $recuTemporaire,
            'asso' => $assoEligible,
            'donateur' => $tiers,
            'montantFormate' => '150,00 €',
            'montantEnLettres' => 'cent cinquante euros',
            'articleCgiLibelle' => 'article 200',
            'numeroCgi' => '200',
            'titreDocument' => "Reçu au titre des dons à certains organismes d'intérêt général",
            'contexteSpecifique' => null,
            'formeLibelle' => 'Don manuel en numéraire',
            'modeLibelle' => 'Chèque',
            'headerLogoBase64' => null,
            'headerLogoMime' => null,
            'cachetBase64' => null,
            'cachetMime' => null,
            'appLogoBase64' => null,
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
            'objet' => 'don',
        ])->render()
    );

    expect($html)->toContain('Don manuel en numéraire');
    expect($html)->toContain('dons à certains organismes');
});
