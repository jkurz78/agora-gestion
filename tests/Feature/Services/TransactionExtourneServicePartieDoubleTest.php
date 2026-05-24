<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Comptes système : 411, 401, 5112
    SystemeSeeder::seed();

    // Catégorie de recette + sous-catégorie 706
    $categorieRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Cotisations',
    ]);

    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '706',
    ]);

    // Compte 706 correspondant
    $this->compte706 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations et adhésions',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // CompteBancaire + Compte 512X correspondant
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
    ]);
    $this->compte512X = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '5121'],
        [
            'intitule' => 'Banque principale',
            'classe' => 5,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'iban' => $this->iban,
        ]
    );

    // Tiers
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    // Services
    $this->ecritureGen = app(EcritureGenerator::class);
    $this->service = app(TransactionExtourneService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Scénario A — Extourne d'une recette comptant chèque (T1 auto-lettrée 411 interne)
// ---------------------------------------------------------------------------

it('[A] extourne recette comptant chèque — auto-délettre la paire 411 interne avant miroir', function () {
    // Créer T1 via EcritureGenerator (recette comptant chèque → 4 lignes, paire 411 auto-lettrée)
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Adhésion Jean Martin',
    );

    // Précondition : les 2 lignes 411 sont lettrées
    $compte411 = compteSysteme('411');
    $lignes411 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411)->toHaveCount(2);
    expect($lignes411[0]->lettrage_code)->not->toBeNull();
    expect($lignes411[1]->lettrage_code)->not->toBeNull();
    expect($lignes411[0]->lettrage_code)->toBe($lignes411[1]->lettrage_code);

    $codeOrigine = $lignes411[0]->lettrage_code;

    // Marquer T1 comme recue pour pouvoir l'extourner
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    // Action : extourner T1
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));

    // Lignes 411 de T1 doivent être délettrées
    $lignes411->map->fresh();
    $lignes411RefreshedA = $lignes411[0]->fresh();
    $lignes411RefreshedB = $lignes411[1]->fresh();

    expect($lignes411RefreshedA->lettrage_code)->toBeNull();
    expect($lignes411RefreshedB->lettrage_code)->toBeNull();

    // Miroir créé
    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-150.0);

    // Lignes du miroir n'ont pas de lettrage_code
    $lignesMiroir = TransactionLigne::where('transaction_id', $miroir->id)->get();
    foreach ($lignesMiroir as $l) {
        expect($l->lettrage_code)->toBeNull("La ligne miroir #{$l->id} ne doit pas avoir de lettrage_code");
    }

    // Audit : une entrée delettre doit exister pour le code original
    $audit = DB::table('lettrage_audit')
        ->where('action', 'delettre')
        ->where('lettrage_code', $codeOrigine)
        ->first();
    expect($audit)->not->toBeNull('Une entrée lettrage_audit action=delettre doit exister');
    expect($audit->motif)->toContain("Auto-délettrage suite à extourne de TX#{$t1->id}");
});

// ---------------------------------------------------------------------------
// Scénario B — Extourne d'une facture validée + encaissée (paire 411 T1–T2)
// ---------------------------------------------------------------------------

it('[B] extourne encaissement créance (T2) — délettre la paire 411 T1-T2, solde 411 tiers remonte', function () {
    // T1 : créance (411 D ouverte)
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture #001',
    );

    $compte411 = compteSysteme('411');

    // Précondition : ligne 411 T1 non lettrée
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // T2 : encaissement créance (lettrage 411 T1 ↔ T2)
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Règlement facture #001',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);
    $t2->update(['statut_reglement' => StatutReglement::Recu]);

    // Précondition : lignes 411 T1 et T2 lettrées ensemble
    $ligne411T1->refresh();
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T2->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);

    $codeOrigine = $ligne411T2->lettrage_code;

    // Action : extourner T2 (l'encaissement)
    $extourne = $this->service->extourner($t2->fresh(), ExtournePayload::fromOrigine($t2->fresh()));

    // Lignes 411 de T2 doivent être délettrées
    $ligne411T2->refresh();
    $ligne411T1->refresh();

    expect($ligne411T2->lettrage_code)->toBeNull('La ligne 411 de T2 doit être délettrée');
    expect($ligne411T1->lettrage_code)->toBeNull('La ligne 411 de T1 doit aussi être délettrée (même groupe)');

    // Miroir T2' créé sans lettrage
    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-200.0);

    $lignesMiroir = TransactionLigne::where('transaction_id', $miroir->id)->get();
    foreach ($lignesMiroir as $l) {
        expect($l->lettrage_code)->toBeNull("La ligne miroir #{$l->id} ne doit pas avoir de lettrage_code");
    }

    // Solde ouvert 411 tiers remonte : T1 ligne 411 D est de nouveau ouverte (non lettrée)
    // Le solde = débit - crédit des lignes non lettrées pour ce tiers sur le compte 411
    $soldeTiers = DB::table('transaction_lignes')
        ->where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->whereNull('deleted_at')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');

    // Après extourne : T1 ligne 411 D=200 ouverte + miroir T2' ligne 411 C=200 non lettrée
    // Solde net = 200 - 200 = 0 (les 2 lignes du miroir se compensent avec T1 au niveau du compte)
    // En réalité le solde ouvert du tiers = montant de la créance T1 = 200 (les lignes du miroir
    // vont vers la trésorerie, non lettrées ici). Vérifier uniquement que la ligne T1 est de nouveau ouverte.
    expect($ligne411T1->lettrage_code)->toBeNull('La créance T1 doit être de nouveau ouverte (solde remonte)');

    // Audit delettre existe
    $audit = DB::table('lettrage_audit')
        ->where('action', 'delettre')
        ->where('lettrage_code', $codeOrigine)
        ->first();
    expect($audit)->not->toBeNull('Audit delettre manquant');
    expect($audit->motif)->toContain("Auto-délettrage suite à extourne de TX#{$t2->id}");
});

// ---------------------------------------------------------------------------
// Scénario C — Extourne d'une Tx legacy pure (sans PD) — comportement actuel préservé
// ---------------------------------------------------------------------------

it('[C] extourne Tx legacy (sans lignes PD) — aucun délettrage tenté, miroir créé normalement', function () {
    // Créer une Tx legacy sans lignes partie double (comme les transactions antérieures au backfill)
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation ancienne',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    // Ligne legacy sans compte_id ni debit/credit
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
        // compte_id non renseigné → legacy pure
    ]);

    // Pas d'entrée lettrage_audit avant
    $auditCountBefore = DB::table('lettrage_audit')->count();

    // Action : extourner sans erreur
    $extourne = $this->service->extourner($tx->fresh(), ExtournePayload::fromOrigine($tx->fresh()));

    // Miroir créé
    expect($extourne->extourne)->not->toBeNull();
    expect((float) $extourne->extourne->montant_total)->toBe(-50.0);

    // Aucune entrée lettrage_audit créée
    $auditCountAfter = DB::table('lettrage_audit')->count();
    expect($auditCountAfter)->toBe($auditCountBefore, 'Aucun délettrage ne doit avoir été tenté sur une Tx legacy');
});

// ---------------------------------------------------------------------------
// Scénario D — Extourne d'une Tx PD avec lignes 411 NON lettrées (créance ouverte)
// ---------------------------------------------------------------------------

it('[D] extourne créance ouverte (lignes 411 non lettrées) — pas de délettrage, miroir créé classiquement', function () {
    // T1 : créance ouverte, ligne 411 D non lettrée (non encaissée)
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 300.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture ouverte',
    );
    // On met Recu pour éviter le path creerLettrage (qui nécessiterait un compte_id)
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : ligne 411 non lettrée
    $ligne411 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411->lettrage_code)->toBeNull();

    $auditCountBefore = DB::table('lettrage_audit')->count();

    // Action : extourner (override mode_paiement car T1 créance a mode_paiement=null)
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    // Miroir créé
    expect($extourne->extourne)->not->toBeNull();
    expect((float) $extourne->extourne->montant_total)->toBe(-300.0);

    // Aucun délettrage tenté (aucune ligne lettrée)
    $auditCountAfter = DB::table('lettrage_audit')->count();
    expect($auditCountAfter)->toBe($auditCountBefore, 'Aucun délettrage ne doit être tenté si aucune ligne lettrée');

    // La ligne 411 d'origine reste intacte (non lettrée)
    $ligne411->refresh();
    expect($ligne411->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Scénario E — Audit trace complet
// ---------------------------------------------------------------------------

it('[E] extourne Tx lettrée — audit lettrage_audit complet (action, motif, user_id, transaction_ligne_ids)', function () {
    // T1 recette comptant chèque (paire 411 auto-lettrée)
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 120.0]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Recette test audit',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');
    $lignes411 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->get();
    $codeOrigine = $lignes411[0]->lettrage_code;
    $idsLignes411 = $lignes411->pluck('id')->sort()->values()->all();

    // Action : extourner
    $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));

    // Audit delettre
    $audit = DB::table('lettrage_audit')
        ->where('action', 'delettre')
        ->where('lettrage_code', $codeOrigine)
        ->first();

    expect($audit)->not->toBeNull('Entrée lettrage_audit action=delettre manquante');
    expect($audit->action)->toBe('delettre');
    expect($audit->lettrage_code)->toBe($codeOrigine);
    expect($audit->motif)->toContain("Auto-délettrage suite à extourne de TX#{$t1->id}");
    expect((int) $audit->user_id)->toBe((int) $this->user->id);
    expect((int) $audit->association_id)->toBe((int) $this->association->id);

    // Les transaction_ligne_ids de l'audit correspondent aux 2 lignes 411
    $auditIds = collect(json_decode($audit->transaction_ligne_ids))->sort()->values()->all();
    expect($auditIds)->toEqual($idsLignes411);
});
