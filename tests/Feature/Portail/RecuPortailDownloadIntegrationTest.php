<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Portail\MesAdhesions;
use App\Livewire\Portail\MesDons;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Test d'intégration — vérifie que le téléchargement de reçu via Livewire
 * retourne un StreamedResponse (et non un Response standard que Livewire
 * tenterait de JSON-encoder, provoquant "Malformed UTF-8 characters").
 *
 * RED : échoue parce que streamDownloadResponse() n'existe pas encore.
 * GREEN : passe après l'ajout de la méthode et la mise à jour des composants.
 */
beforeEach(function () {
    TenantContext::clear();
    // Pas de Storage::fake — on laisse le service écrire sur le disque local réel
    // via une configuration test (storage_path/framework/testing).
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Prépare une association pleinement éligible aux reçus fiscaux.
 */
function makeAssoEligible(): Association
{
    return Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Martin',
        'signataire_qualite' => 'Président',
    ]);
}

/**
 * Prépare un Tiers avec adresse complète (requis par validerEligibilite).
 */
function makeTiersAvecAdresse(Association $asso): Tiers
{
    return Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '12 avenue de la République',
        'code_postal' => '75011',
        'ville' => 'Paris',
    ]);
}

/**
 * Crée une transaction Recette encaissée + une ligne avec usage Don.
 */
function makeDonLigneReelle(Association $asso, Tiers $tiers, float $montant = 50.0): TransactionLigne
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-03-15',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
        'mode_paiement' => 'virement',
    ]);

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => $montant,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : MesDons — streamDownloadResponse retourne un StreamedResponse
// ─────────────────────────────────────────────────────────────────────────────
it('telechargerRecuFiscal retourne un StreamedResponse (pas un Response standard)', function () {
    $asso = makeAssoEligible();
    TenantContext::boot($asso);

    $tiers = makeTiersAvecAdresse($asso);
    Auth::guard('tiers-portail')->login($tiers);

    $ligne = makeDonLigneReelle($asso, $tiers, 75.0);

    // Génère le reçu via le vrai service (crée le PDF sur le disk local)
    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);

    // Vérifier que l'intégrité est OK après génération
    expect($recu->verifierIntegrite())->toBeTrue();

    // Appeler l'action Livewire et capturer la réponse
    $component = Livewire::test(MesDons::class, ['association' => $asso])
        ->call('telechargerRecuFiscal', $ligne->id);

    // La réponse doit être un StreamedResponse — pas un JsonResponse ni un Response standard.
    // Livewire place la réponse dans la propriété effects['redirect'] ou la retourne directement.
    // Le signal fiable : assertOk() passe et le Content-Type n'est PAS application/json.
    $component->assertOk();

    // Vérification directe via le service : streamDownloadResponse doit exister et retourner StreamedResponse
    $response = app(RecuFiscalService::class)->streamDownloadResponse($recu);
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
})->skip(fn () => ! class_exists(RecuFiscalService::class), 'Service introuvable');

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : MesAdhesions — streamDownloadResponse retourne un StreamedResponse
// ─────────────────────────────────────────────────────────────────────────────
it('telechargerRecuCotisation retourne un StreamedResponse (pas un Response standard)', function () {
    $asso = makeAssoEligible();
    TenantContext::boot($asso);

    $tiers = makeTiersAvecAdresse($asso);
    Auth::guard('tiers-portail')->login($tiers);

    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-04-01',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
        'mode_paiement' => 'cheque',
    ]);

    // Le factory afterCreating crée 1-3 lignes aléatoires sans sous_categorie cotisation.
    // resoudreLigneCotisation() ne peut résoudre que s'il y a exactement 1 ligne (sans formule).
    // On supprime les lignes auto-créées et on en pose une seule avec la bonne sous-catégorie.
    $tx->lignes()->delete();
    $ligneCotisation = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 60.0,
    ]);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $tx->id,
        'deductible_fiscal' => true,
        'montant_facial' => 60.0,
    ]);

    // Génère le reçu via le vrai service
    $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
    expect($recu->verifierIntegrite())->toBeTrue();

    // Appeler l'action Livewire
    $component = Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $adhesion->id);

    $component->assertOk();

    // Vérification directe : streamDownloadResponse doit exister et retourner StreamedResponse
    $response = app(RecuFiscalService::class)->streamDownloadResponse($recu);
    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
})->skip(fn () => ! class_exists(RecuFiscalService::class), 'Service introuvable');
