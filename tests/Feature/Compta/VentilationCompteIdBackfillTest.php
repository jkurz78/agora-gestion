<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\EncadrementPrevision;
use App\Models\Facture;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\UsageSousCategorie;
use App\Models\User;
use App\Services\Compta\Migrations\VentilationCompteIdBackfiller;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * DC-2 — dissolution sous_categories → comptes.
 *
 * Peuple `compte_id` sur les 10 tables de ventilation encore limitées à
 * `sous_categorie_id`, via la même règle de mapping que `transaction_lignes`
 * (déjà backfillée en amont, hors périmètre ici) :
 *   sous_categories.code_cerfa = comptes.numero_pcg (même association_id).
 *
 * Note : `SousCategorieCompteObserver` matérialise déjà automatiquement le
 * Compte correspondant dès que `code_cerfa` (classe 6/7) est posé sur une
 * sous-catégorie — inutile (et en conflit de contrainte unique) de le
 * recréer manuellement ici.
 */
function creerSousCategorieEtCompte(int $associationId, string $codeCerfa = '706A'): SousCategorie
{
    return SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => $codeCerfa,
    ]);
}

it('remplit compte_id sur budget_lines (cas représentatif)', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieEtCompte($associationId);

    // Insertion minimale via DB::table pour simuler une ligne "legacy" créée
    // avant DC-3 (le trait SyncCompteDepuisSousCategorie synchroniserait
    // compte_id dès la création si on passait par Eloquent).
    $budgetLineId = DB::table('budget_lines')->insertGetId([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
        'exercice' => 2025,
        'montant_prevu' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resultat = VentilationCompteIdBackfiller::backfill();

    $compte = Compte::where('numero_pcg', '706A')->where('association_id', $associationId)->first();

    expect((int) DB::table('budget_lines')->where('id', $budgetLineId)->value('compte_id'))->toBe((int) $compte->id)
        ->and($resultat['budget_lines'])->toBe(1);
});

it('couvre les 10 tables de ventilation', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieEtCompte($associationId);
    $scId = $sousCategorie->id;

    // budget_lines (pas de factory ici — insertion minimale via DB::table pour
    // simuler une ligne "legacy" créée avant DC-3, cf. commentaire ci-dessus)
    $budgetLineId = DB::table('budget_lines')->insertGetId([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
        'exercice' => 2025,
        'montant_prevu' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // facture_lignes (pas de factory — insertion minimale via DB::table)
    $facture = Facture::factory()->create(['association_id' => $associationId]);
    $factureLigneId = DB::table('facture_lignes')->insertGetId([
        'facture_id' => $facture->id,
        'type' => 'montant',
        'libelle' => 'Ligne test',
        'montant' => 100,
        'ordre' => 1,
        'sous_categorie_id' => $scId,
    ]);

    // devis_lignes
    $devis = Devis::factory()->create(['association_id' => $associationId]);
    $devisLigne = DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'sous_categorie_id' => $scId,
    ]);

    // formules_adhesion
    $formule = FormuleAdhesion::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
    ]);

    // type_operations
    $typeOperation = TypeOperation::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
    ]);

    // helloasso_form_mappings (pas de factory — insertion minimale via DB::table)
    $helloAssoParametres = HelloAssoParametres::factory()->create(['association_id' => $associationId]);
    $mappingId = DB::table('helloasso_form_mappings')->insertGetId([
        'helloasso_parametres_id' => $helloAssoParametres->id,
        'form_slug' => 'form-test',
        'form_type' => 'Membership',
        'sous_categorie_id' => $scId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // provisions
    $user = User::factory()->create();
    $provision = Provision::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
        'saisi_par' => $user->id,
    ]);

    // usages_sous_categories
    $usage = UsageSousCategorie::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
    ]);

    // notes_de_frais_lignes
    $noteDeFrais = NoteDeFrais::factory()->create(['association_id' => $associationId]);
    $noteDeFraisLigne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $noteDeFrais->id,
        'sous_categorie_id' => $scId,
    ]);

    // encadrement_previsions
    $operation = Operation::factory()->create(['association_id' => $associationId]);
    $tiers = Tiers::factory()->create(['association_id' => $associationId]);
    $seance = Seance::factory()->create(['operation_id' => $operation->id]);
    $encadrementPrevision = EncadrementPrevision::factory()->create([
        'association_id' => $associationId,
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $scId,
        'seance_id' => $seance->id,
    ]);

    VentilationCompteIdBackfiller::backfill();

    $compte = Compte::where('numero_pcg', '706A')->where('association_id', $associationId)->first();
    $compteId = (int) $compte->id;

    expect((int) DB::table('budget_lines')->where('id', $budgetLineId)->value('compte_id'))->toBe($compteId)
        ->and((int) DB::table('facture_lignes')->where('id', $factureLigneId)->value('compte_id'))->toBe($compteId)
        ->and((int) $devisLigne->refresh()->compte_id)->toBe($compteId)
        ->and((int) $formule->refresh()->compte_id)->toBe($compteId)
        ->and((int) $typeOperation->refresh()->compte_id)->toBe($compteId)
        ->and((int) DB::table('helloasso_form_mappings')->where('id', $mappingId)->value('compte_id'))->toBe($compteId)
        ->and((int) $provision->refresh()->compte_id)->toBe($compteId)
        ->and((int) $usage->refresh()->compte_id)->toBe($compteId)
        ->and((int) $noteDeFraisLigne->refresh()->compte_id)->toBe($compteId)
        ->and((int) $encadrementPrevision->refresh()->compte_id)->toBe($compteId);
});

it('une sous-catégorie sans code_cerfa n est pas mappée', function () {
    $associationId = TenantContext::currentId();

    $sousCategorie = SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => null,
    ]);

    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    $resultat = VentilationCompteIdBackfiller::backfill();

    expect($budgetLine->refresh()->compte_id)->toBeNull()
        ->and($resultat['non_mappees'])->toBeGreaterThanOrEqual(1);
});

it('est idempotent : un second passage ne modifie plus rien', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieEtCompte($associationId);

    // Insertion minimale via DB::table pour simuler une ligne "legacy" (cf. plus haut).
    DB::table('budget_lines')->insert([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
        'exercice' => 2025,
        'montant_prevu' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $premierPassage = VentilationCompteIdBackfiller::backfill();
    $secondPassage = VentilationCompteIdBackfiller::backfill();

    expect($premierPassage['budget_lines'])->toBe(1)
        ->and($secondPassage['budget_lines'])->toBe(0);
});

it('respecte l isolation multi-tenant : chaque association reçoit son propre compte', function () {
    $associationA = Association::factory()->create();
    $associationB = Association::factory()->create();

    TenantContext::boot($associationA);
    $categorieA = Categorie::factory()->create(['association_id' => $associationA->id]);
    $sousCategorieA = SousCategorie::factory()->create([
        'association_id' => $associationA->id,
        'categorie_id' => $categorieA->id,
        'code_cerfa' => '706A',
    ]);
    $compteA = Compte::where('association_id', $associationA->id)->where('numero_pcg', '706A')->firstOrFail();
    $budgetLineA = BudgetLine::factory()->create([
        'association_id' => $associationA->id,
        'sous_categorie_id' => $sousCategorieA->id,
    ]);

    TenantContext::boot($associationB);
    $categorieB = Categorie::factory()->create(['association_id' => $associationB->id]);
    $sousCategorieB = SousCategorie::factory()->create([
        'association_id' => $associationB->id,
        'categorie_id' => $categorieB->id,
        'code_cerfa' => '706A',
    ]);
    $compteB = Compte::where('association_id', $associationB->id)->where('numero_pcg', '706A')->firstOrFail();
    $budgetLineB = BudgetLine::factory()->create([
        'association_id' => $associationB->id,
        'sous_categorie_id' => $sousCategorieB->id,
    ]);

    VentilationCompteIdBackfiller::backfill();

    expect((int) $budgetLineA->refresh()->compte_id)->toBe((int) $compteA->id)
        ->and((int) $budgetLineB->refresh()->compte_id)->toBe((int) $compteB->id)
        ->and((int) $budgetLineA->compte_id)->not->toBe((int) $budgetLineB->compte_id);
});
