<?php

declare(strict_types=1);

use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Concerns\SyncCompteDepuisSousCategorie;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\EncadrementPrevision;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\UsageSousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * DC-3 — dissolution sous_categories → comptes.
 *
 * Tant que les écrans continuent d'écrire uniquement sous_categorie_id (bascule
 * en DC-8), le trait SyncCompteDepuisSousCategorie garantit que compte_id est
 * rempli au saving, via le même mapping que le backfill DC-2 :
 *   sous_categories.code_cerfa = comptes.numero_pcg (même association_id).
 *
 * Note : SousCategorieCompteObserver matérialise déjà automatiquement le Compte
 * correspondant dès que code_cerfa (classe 6/7) est posé sur une sous-catégorie
 * — on ne crée donc jamais le Compte manuellement, on va chercher celui qui a
 * été auto-créé.
 */
function creerSousCategorieAvecCompte(int $associationId, string $codeCerfa = '706A'): SousCategorie
{
    return SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => $codeCerfa,
    ]);
}

function compteMappe(int $associationId, string $codeCerfa): Compte
{
    return Compte::where('numero_pcg', $codeCerfa)
        ->where('association_id', $associationId)
        ->firstOrFail();
}

it('remplit compte_id à la création quand seule la sous-catégorie est fournie', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieAvecCompte($associationId);
    $compte = compteMappe($associationId, '706A');

    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    expect((int) $budgetLine->compte_id)->toBe((int) $compte->id);
});

it('ne remplace pas un compte_id posé explicitement', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieAvecCompte($associationId, '706A');
    compteMappe($associationId, '706A');

    $autreSousCategorie = creerSousCategorieAvecCompte($associationId, '707A');
    $autreCompte = compteMappe($associationId, '707A');

    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
        'compte_id' => $autreCompte->id,
    ]);

    expect((int) $budgetLine->compte_id)->toBe((int) $autreCompte->id);
});

it('resynchronise compte_id quand la sous-catégorie change', function () {
    $associationId = TenantContext::currentId();
    $sousCategorieA = creerSousCategorieAvecCompte($associationId, '706A');
    $compteA = compteMappe($associationId, '706A');

    $sousCategorieB = creerSousCategorieAvecCompte($associationId, '707A');
    $compteB = compteMappe($associationId, '707A');

    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorieA->id,
    ]);

    expect((int) $budgetLine->compte_id)->toBe((int) $compteA->id);

    $budgetLine->sous_categorie_id = $sousCategorieB->id;
    $budgetLine->save();

    expect((int) $budgetLine->refresh()->compte_id)->toBe((int) $compteB->id);
});

it('laisse compte_id null si la sous-catégorie n a pas de code_cerfa', function () {
    $associationId = TenantContext::currentId();

    $sousCategorie = SousCategorie::factory()->create([
        'association_id' => $associationId,
        'code_cerfa' => null,
    ]);

    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    expect($budgetLine->compte_id)->toBeNull();
});

it('couvre les 10 modèles', function () {
    $associationId = TenantContext::currentId();
    $sousCategorie = creerSousCategorieAvecCompte($associationId);
    $scId = $sousCategorie->id;
    $compte = compteMappe($associationId, '706A');
    $compteId = (int) $compte->id;

    // budget_lines
    $budgetLine = BudgetLine::factory()->create([
        'association_id' => $associationId,
        'sous_categorie_id' => $scId,
    ]);

    // facture_lignes (pas de factory — forceCreate pour déclencher le hook saving)
    $facture = Facture::factory()->create(['association_id' => $associationId]);
    $factureLigne = FactureLigne::forceCreate([
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

    // helloasso_form_mappings (pas de factory — forceCreate pour déclencher le hook saving)
    $helloAssoParametres = HelloAssoParametres::factory()->create(['association_id' => $associationId]);
    $mapping = HelloAssoFormMapping::forceCreate([
        'helloasso_parametres_id' => $helloAssoParametres->id,
        'form_slug' => 'form-test',
        'form_type' => 'Membership',
        'sous_categorie_id' => $scId,
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

    expect((int) $budgetLine->compte_id)->toBe($compteId)
        ->and((int) $factureLigne->compte_id)->toBe($compteId)
        ->and((int) $devisLigne->compte_id)->toBe($compteId)
        ->and((int) $formule->compte_id)->toBe($compteId)
        ->and((int) $typeOperation->compte_id)->toBe($compteId)
        ->and((int) $mapping->compte_id)->toBe($compteId)
        ->and((int) $provision->compte_id)->toBe($compteId)
        ->and((int) $usage->compte_id)->toBe($compteId)
        ->and((int) $noteDeFraisLigne->compte_id)->toBe($compteId)
        ->and((int) $encadrementPrevision->compte_id)->toBe($compteId);
});

it('TransactionLigne n est PAS affectée par le trait', function () {
    expect(in_array(SyncCompteDepuisSousCategorie::class, class_uses_recursive(TransactionLigne::class), true))
        ->toBeFalse();
});
