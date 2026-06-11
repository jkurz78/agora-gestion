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

    // Miroir créé
    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-150.0);

    // Lignes 411 de T1 : d'abord délettrées (auto-délettrage), puis re-lettrées par cross-lettrage
    // avec les lignes 411 symétriques du miroir. Le code de lettrage est nouveau (différent de $codeOrigine).
    $lignes411RefreshedA = $lignes411[0]->fresh();
    $lignes411RefreshedB = $lignes411[1]->fresh();

    expect($lignes411RefreshedA->lettrage_code)->not->toBeNull('T1.411D doit être re-lettrée avec miroir');
    expect($lignes411RefreshedB->lettrage_code)->not->toBeNull('T1.411C doit être re-lettrée avec miroir');
    // Les deux lignes 411 de T1 sont cross-lettrées avec des lignes miroir différentes
    // (T1.411D↔Miroir.411C et T1.411C↔Miroir.411D — paires distinctes)
    // Note : les codes sont distincts entre eux mais pas nécessairement différents de $codeOrigine
    // (l'auto-délettrage a vidé les lignes donc le compteur peut réutiliser le même code alphanumérique)

    // Lignes 411 du miroir sont aussi lettrées (cross-lettrées avec T1)
    $lignes411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->get();
    foreach ($lignes411Miroir as $l) {
        expect($l->lettrage_code)->not->toBeNull("La ligne 411 miroir #{$l->id} doit être cross-lettrée");
    }

    // Lignes non-tiers du miroir (706, 512X) n'ont pas de lettrage_code
    $lignesMiroirNonTiers = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', '!=', (int) $compte411->id)
        ->whereNotNull('compte_id')
        ->get();
    foreach ($lignesMiroirNonTiers as $l) {
        expect($l->lettrage_code)->toBeNull("La ligne non-tiers miroir #{$l->id} ne doit pas avoir de lettrage_code");
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

    // Recharger toutes les lignes
    $ligne411T2->refresh();
    $ligne411T1->refresh();

    // Miroir T2' créé
    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-200.0);

    // T2.411C : cross-lettrée avec Miroir.411D (inversion de l'encaissement)
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T2->lettrage_code)->not->toBeNull('T2.411C doit être cross-lettrée avec le miroir');
    expect($ligne411Miroir->lettrage_code)->not->toBeNull('Miroir.411D doit être cross-lettré avec T2');
    expect($ligne411T2->lettrage_code)->toBe($ligne411Miroir->lettrage_code);

    // T1.411D : reste ouverte (la créance est libérée — remonte au solde tiers)
    expect($ligne411T1->lettrage_code)->toBeNull('La créance T1 doit être de nouveau ouverte (solde remonte)');

    // Lignes non-tiers du miroir (512X) n'ont pas de lettrage_code
    $lignesMiroirNonTiers = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', '!=', (int) $compte411->id)
        ->whereNotNull('compte_id')
        ->get();
    foreach ($lignesMiroirNonTiers as $l) {
        expect($l->lettrage_code)->toBeNull("La ligne non-tiers miroir #{$l->id} ne doit pas avoir de lettrage_code");
    }

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

    $deletetrCountBefore = DB::table('lettrage_audit')->where('action', 'delettre')->count();

    // Action : extourner (override mode_paiement car T1 créance a mode_paiement=null)
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    // Miroir créé
    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-300.0);

    // Aucun auto-délettrage tenté (aucune ligne préalablement lettrée)
    $deletetrCountAfter = DB::table('lettrage_audit')->where('action', 'delettre')->count();
    expect($deletetrCountAfter)->toBe($deletetrCountBefore, 'Aucun auto-délettrage ne doit être tenté si aucune ligne était lettrée');

    // La ligne 411 d'origine est maintenant cross-lettrée avec la ligne 411 du miroir
    $ligne411->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411->lettrage_code)->not->toBeNull('T1.411D doit être cross-lettrée avec le miroir');
    expect($ligne411->lettrage_code)->toBe($ligne411Miroir->lettrage_code);
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

// ---------------------------------------------------------------------------
// Scénario F — Header PD sur le miroir
// ---------------------------------------------------------------------------

it('[F] miroir porte equilibree=true, type_ecriture=extourne, journal=origine', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test header PD',
    );

    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    // Override mode_paiement car T1 créance a mode_paiement=null
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    $miroir = $extourne->extourne;
    expect($miroir->equilibree)->toBeTrue();
    expect($miroir->type_ecriture)->toBe('extourne');
    expect($miroir->journal)->toBe($t1->fresh()->journal);
});

// ---------------------------------------------------------------------------
// Scénario G — Lignes PD inversées D↔C sur le miroir
// ---------------------------------------------------------------------------

it('[G] miroir d\'une recette à crédit porte les lignes PD avec D↔C inversé', function () {
    // T1 : 411 D=120 (tiers) + 706 C=120
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 120.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test inversion D/C',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    $compte411 = compteSysteme('411');

    // Lignes PD du miroir (compte_id IS NOT NULL)
    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    // 2 lignes PD : 411 et 706 (inversées)
    expect($lignesPD)->toHaveCount(2);

    // Ligne 411 inversée : debit=0, credit=120 (swap de l'original 411 D=120)
    $ligne411 = $lignesPD->firstWhere('compte_id', (int) $compte411->id);
    expect($ligne411)->not->toBeNull('Miroir doit avoir une ligne 411');
    expect((float) $ligne411->debit)->toBe(0.0);
    expect((float) $ligne411->credit)->toBe(120.0);
    expect((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);

    // Ligne 706 inversée : debit=120, credit=0 (swap de l'original 706 C=120)
    $ligne706 = $lignesPD->firstWhere('compte_id', (int) $this->compte706->id);
    expect($ligne706)->not->toBeNull('Miroir doit avoir une ligne 706');
    expect((float) $ligne706->debit)->toBe(120.0);
    expect((float) $ligne706->credit)->toBe(0.0);
    expect($ligne706->tiers_id)->toBeNull();
});

it('[G2] lignes PD du miroir sont équilibrées (sum D = sum C)', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 250.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test équilibre miroir',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    $totalDebit = $lignesPD->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lignesPD->sum(fn ($l) => (float) $l->credit);

    expect(bccomp((string) $totalDebit, (string) $totalCredit, 2))->toBe(0);
});

// ---------------------------------------------------------------------------
// Scénario H — Cross-lettrage tiers après extourne
// ---------------------------------------------------------------------------

it('[H] extourne recette à crédit — cross-lettrage 411 origine ↔ miroir', function () {
    // T1 : 411 D=200 (tiers, non lettrée — créance ouverte) + 706 C=200
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance ouverte',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : ligne 411 T1 ouverte
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    // Lignes 411 : T1 D=200 et Miroir C=200 doivent être lettrées ensemble
    $ligne411T1->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();

    expect($ligne411T1->lettrage_code)->not->toBeNull('Ligne 411 T1 doit être lettrée');
    expect($ligne411Miroir->lettrage_code)->not->toBeNull('Ligne 411 miroir doit être lettrée');
    expect($ligne411T1->lettrage_code)->toBe($ligne411Miroir->lettrage_code);

    // Solde 411 pour ce tiers = 0 (D=200, C=200, tout lettré)
    $solde = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect((float) $solde)->toBe(0.0, 'Solde 411 tiers = 0 après extourne d\'une créance ouverte');
});

it('[H2] extourne recette comptant (T1+T2) — cross-lettrage 411 T1↔miroir, T2.411C reste ouverte', function () {
    // T1 : créance → 411 D=200 (tiers) + 706 C=200
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture T1',
    );

    // T2 : encaissement → portage D=200 + 411 C=200 (tiers), auto-lettrage T1.411D↔T2.411C
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement T2',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : T1.411D et T2.411C sont lettrées
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);

    // Action : extourner T1
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    // Recharger
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)->firstOrFail();

    // T1.411D ↔ Miroir.411C : cross-lettrées
    expect($ligne411T1->lettrage_code)->not->toBeNull('T1.411D doit être lettrée avec miroir');
    expect($ligne411Miroir->lettrage_code)->not->toBeNull('Miroir.411C doit être lettrée');
    expect($ligne411T1->lettrage_code)->toBe($ligne411Miroir->lettrage_code);

    // T2.411C : ouverte (le remboursement est en attente → obligation envers le tiers)
    expect($ligne411T2->lettrage_code)->toBeNull('T2.411C doit rester ouverte (refund pending)');

    // Solde 411 ouvert pour ce tiers = -200 (T2.411C non lettrée = on doit rembourser)
    $soldeOuvert = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($soldeOuvert)->toBe(-200.0, 'Solde ouvert 411 = -200 (on doit rembourser le tiers)');
});

it('[H3] extourne recette comptant OLD pattern (4 lignes T1) — double cross-lettrage 411', function () {
    // T1 OLD pattern : 411 D=150 + 706 C=150 + 5112 D=150 + 411 C=150
    // Paire 411 interne auto-lettrée
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Recette comptant OLD',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : 2 lignes 411 internes lettrées
    $lignes411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();
    expect($lignes411T1)->toHaveCount(2);
    expect($lignes411T1[0]->lettrage_code)->toBe($lignes411T1[1]->lettrage_code);

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh())
    );
    $miroir = $extourne->extourne;

    // Recharger
    $lignes411T1->each->refresh();

    $lignes411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();
    expect($lignes411Miroir)->toHaveCount(2);

    // Toutes les 4 lignes 411 doivent être lettrées (en paires D↔C)
    $toutesLignes411 = $lignes411T1->merge($lignes411Miroir);
    foreach ($toutesLignes411 as $l) {
        expect($l->fresh()->lettrage_code)->not->toBeNull("Ligne 411 #{$l->id} doit être lettrée");
    }

    // Solde 411 ouvert = 0 (tout lettré, pas de dette)
    $solde = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0);
});
