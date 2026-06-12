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
use App\Models\Extourne;
use App\Models\RemiseBancaire;
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

    // Catégorie de dépense + sous-catégorie 601
    $categorieDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Achats',
    ]);

    $this->sc601 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieDepense->id,
        'nom' => 'Achats fournitures',
        'code_cerfa' => '601',
    ]);

    $this->compte601 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '601'],
        [
            'intitule' => 'Achats fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // Services
    $this->ecritureGen = app(EcritureGenerator::class);
    $this->service = app(TransactionExtourneService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Scénario A — Extourne d'une recette comptant chèque (paire 411 interne)
//   Le lettrage interne 411 D↔C de T1 reste INTACT — pas de délettrage.
//   Les lignes 411 du miroir ne sont pas lettrées (pas de cross-lettrage).
// ---------------------------------------------------------------------------

it('[A] extourne recette comptant chèque — lettrage interne 411 préservé, miroir non lettré', function () {
    $t1 = $this->ecritureGen->pourRecetteComptant(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 150.0]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-01'),
        libelle: 'Adhésion Jean Martin',
    );

    // Précondition : les 2 lignes 411 de T1 sont lettrées ensemble
    $compte411 = compteSysteme('411');
    $lignes411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->get();
    expect($lignes411T1)->toHaveCount(2);
    expect($lignes411T1[0]->lettrage_code)->not->toBeNull();
    expect($lignes411T1[0]->lettrage_code)->toBe($lignes411T1[1]->lettrage_code);

    $codeOrigine = $lignes411T1[0]->lettrage_code;

    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    // Action : extourner T1
    $extourne = $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));

    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-150.0);

    // Lettrage interne 411 de T1 : INTACT (même code qu'avant)
    $lignes411T1->each->refresh();
    expect($lignes411T1[0]->lettrage_code)->toBe($codeOrigine);
    expect($lignes411T1[1]->lettrage_code)->toBe($codeOrigine);

    // Lignes 411 du miroir : PAS lettrées (pas de cross-lettrage)
    $lignes411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->get();
    foreach ($lignes411Miroir as $l) {
        expect($l->lettrage_code)->toBeNull("Ligne 411 miroir #{$l->id} ne doit PAS être lettrée");
    }

    // Lignes non-tiers du miroir (706, 512X) n'ont pas de lettrage_code
    $lignesMiroirNonTiers = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', '!=', (int) $compte411->id)
        ->whereNotNull('compte_id')
        ->get();
    foreach ($lignesMiroirNonTiers as $l) {
        expect($l->lettrage_code)->toBeNull("Ligne non-tiers miroir #{$l->id} ne doit pas avoir de lettrage_code");
    }

    // Aucun audit delettre
    expect(DB::table('lettrage_audit')->where('action', 'delettre')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scénario B — Extourne d'un encaissement créance (T2) — lettrage T1↔T2 préservé
//   Le lettrage 411 entre T1 et T2 reste INTACT.
//   Le miroir 411 reste ouvert = dette de remboursement.
// ---------------------------------------------------------------------------

it('[B] extourne encaissement créance (T2) — lettrage T1↔T2 préservé, miroir 411 ouvert', function () {
    // T1 : créance (411 D ouverte)
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture #001',
    );

    $compte411 = compteSysteme('411');

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
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);

    $codeOrigine = $ligne411T1->lettrage_code;

    // Action : extourner T2 (l'encaissement)
    $extourne = $this->service->extourner($t2->fresh(), ExtournePayload::fromOrigine($t2->fresh()));

    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-200.0);

    // Lettrage T1↔T2 : INTACT (même code)
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->toBe($codeOrigine);
    expect($ligne411T2->lettrage_code)->toBe($codeOrigine);

    // Miroir 411 : ouvert (pas de lettrage_code) — dette de remboursement
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411Miroir->lettrage_code)->toBeNull('Miroir 411 doit rester ouvert = dette de remboursement');

    // Aucun audit delettre
    expect(DB::table('lettrage_audit')->where('action', 'delettre')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scénario C — Extourne d'une Tx legacy pure (sans PD) — comportement préservé
// ---------------------------------------------------------------------------

it('[C] extourne Tx legacy (sans lignes PD) — miroir créé normalement, aucun délettrage', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation ancienne',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    $auditCountBefore = DB::table('lettrage_audit')->count();

    $extourne = $this->service->extourner($tx->fresh(), ExtournePayload::fromOrigine($tx->fresh()));

    expect($extourne->extourne)->not->toBeNull();
    expect((float) $extourne->extourne->montant_total)->toBe(-50.0);

    $auditCountAfter = DB::table('lettrage_audit')->count();
    expect($auditCountAfter)->toBe($auditCountBefore, 'Aucun délettrage sur Tx legacy');
});

// ---------------------------------------------------------------------------
// Scénario D — Extourne d'une Tx PD créance ouverte (411 non lettrée)
// ---------------------------------------------------------------------------

it('[D] extourne créance ouverte (411 non lettrée) — miroir créé, 411 reste ouverte', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 300.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture ouverte',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : ligne 411 non lettrée
    $ligne411 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411->lettrage_code)->toBeNull();

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-300.0);

    // T1.411D reste ouverte
    $ligne411->refresh();
    expect($ligne411->lettrage_code)->toBeNull('T1.411D reste ouverte');

    // Miroir.411C aussi ouverte (pas de cross-lettrage)
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411Miroir->lettrage_code)->toBeNull('Miroir.411C non lettrée');

    // Le grand livre s'équilibre : T1 411 D=300 + Miroir 411 C=300 → solde brut = 0
    $solde = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0, 'Grand livre 411 soldé à zéro');

    // Aucun délettrage
    expect(DB::table('lettrage_audit')->where('action', 'delettre')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scénario E — Aucun audit delettre après extourne (plus de délettrage)
// ---------------------------------------------------------------------------

it('[E] extourne Tx lettrée — aucun audit delettre (les lettrages existants sont préservés)', function () {
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
        ->where('compte_id', $compte411->id)->get();
    $codeOrigine = $lignes411[0]->lettrage_code;

    // Action : extourner
    $this->service->extourner($t1->fresh(), ExtournePayload::fromOrigine($t1->fresh()));

    // Aucun audit delettre créé
    expect(DB::table('lettrage_audit')->where('action', 'delettre')->count())->toBe(0);

    // Le lettrage d'origine est intact
    $lignes411->each->refresh();
    expect($lignes411[0]->lettrage_code)->toBe($codeOrigine);
    expect($lignes411[1]->lettrage_code)->toBe($codeOrigine);
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

    $lignesPD = TransactionLigne::where('transaction_id', $miroir->id)
        ->whereNotNull('compte_id')
        ->get();

    expect($lignesPD)->toHaveCount(2);

    // Ligne 411 inversée : debit=0, credit=120
    $ligne411 = $lignesPD->firstWhere('compte_id', (int) $compte411->id);
    expect($ligne411)->not->toBeNull('Miroir doit avoir une ligne 411');
    expect((float) $ligne411->debit)->toBe(0.0);
    expect((float) $ligne411->credit)->toBe(120.0);
    expect((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);

    // Ligne 706 inversée : debit=120, credit=0
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
// Scénario H — Extourne créance ouverte — pas de cross-lettrage, le grand livre
//   s'équilibre naturellement (D=200 + C=200 sur le 411)
// ---------------------------------------------------------------------------

it('[H] extourne recette à crédit — pas de cross-lettrage, grand livre 411 soldé', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Créance ouverte',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    // Lignes 411 : aucun lettrage (ni T1, ni miroir)
    $ligne411T1->refresh();
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)->firstOrFail();

    expect($ligne411T1->lettrage_code)->toBeNull('T1.411 reste non lettrée');
    expect($ligne411Miroir->lettrage_code)->toBeNull('Miroir.411 non lettrée');

    // Le grand livre 411 tiers se compense : D=200 (T1) + C=200 (miroir) = 0
    $solde = (float) TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $this->tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0, 'Grand livre 411 = 0 après extourne');
});

it('[H2] extourne T1 quand T2 existe non remisée — pas de cascade, T2 intacte', function () {
    // T1 : créance → 411 D=200 + 706 C=200
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture T1',
    );

    // T2 : encaissement → 5112 D=200 + 411 C=200, auto-lettrage T1.411D↔T2.411C
    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement T2',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte411 = compteSysteme('411');

    // Précondition : T1.411D et T2.411C lettrées ensemble
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    $codeOrigine = $ligne411T1->lettrage_code;
    expect($codeOrigine)->toBe($ligne411T2->lettrage_code);

    // Action : extourner T1 (ne cascade PAS sur T2)
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    // T2 n'est PAS extournée (pas de cascade)
    $t2->refresh();
    expect($t2->extournee_at)->toBeNull('T2 ne doit PAS être extournée (pas de cascade)');

    // Lettrage T1↔T2 préservé
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->toBe($codeOrigine);
    expect($ligne411T2->lettrage_code)->toBe($codeOrigine);

    // Miroir T1 créé, 411C ouverte
    $miroirT1 = $extourne->extourne;
    $ligne411Miroir = TransactionLigne::where('transaction_id', $miroirT1->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411Miroir->lettrage_code)->toBeNull('Miroir 411 ouvert = dette de remboursement');
});

it('[H2b] extourne T1 quand T2 est remisée — pas de cascade, T2 intacte', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 200.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture T1 remisée',
    );

    $t2 = $this->ecritureGen->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-15'),
        libelle: 'Encaissement T2 remisé',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    // Simuler que T2 est dans une remise
    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise test',
        'saisi_par' => $this->user->id,
    ]);
    $t2->update(['remise_id' => $remise->id]);

    $compte411 = compteSysteme('411');

    // Action : extourner T1
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );

    // T2 ne doit PAS être extournée (pas de cascade)
    $t2->refresh();
    expect($t2->extournee_at)->toBeNull('T2 remisée ne doit PAS être extournée');

    // T2.411C reste ouverte (pas de modification)
    $ligne411T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte411->id)->firstOrFail();
    expect($ligne411T2->lettrage_code)->not->toBeNull('T2.411C reste lettrée avec T1');
});

it('[H3] extourne recette comptant OLD pattern (4 lignes T1) — lettrages internes préservés', function () {
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
    $codeOrigine = $lignes411T1[0]->lettrage_code;
    expect($codeOrigine)->toBe($lignes411T1[1]->lettrage_code);

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh())
    );
    $miroir = $extourne->extourne;

    // Lettrages internes T1 : INTACTS
    $lignes411T1->each->refresh();
    expect($lignes411T1[0]->lettrage_code)->toBe($codeOrigine);
    expect($lignes411T1[1]->lettrage_code)->toBe($codeOrigine);

    // Lignes 411 du miroir : PAS lettrées
    $lignes411Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();
    expect($lignes411Miroir)->toHaveCount(2);
    foreach ($lignes411Miroir as $l) {
        expect($l->lettrage_code)->toBeNull("Miroir 411 #{$l->id} ne doit PAS être lettrée");
    }
});

// ---------------------------------------------------------------------------
// Scénario I — Dépense : symétrie 401
// ---------------------------------------------------------------------------

it('[I] extourne dépense à crédit — lignes PD inversées, lettrages préservés', function () {
    // T1 : 601 D=300 + 401 C=300 (tiers, dette ouverte)
    $t1 = $this->ecritureGen->pourDepenseACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte601, 'montant' => 300.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fournisseur',
    );
    $t1->update(['statut_reglement' => StatutReglement::Recu]);

    $compte401 = compteSysteme('401');

    // Précondition : 401 C=300 ouverte
    $ligne401T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)->firstOrFail();
    expect($ligne401T1->lettrage_code)->toBeNull();
    expect((float) $ligne401T1->credit)->toBe(300.0);

    // Action
    $extourne = $this->service->extourner(
        $t1->fresh(),
        ExtournePayload::fromOrigine($t1->fresh(), ['mode_paiement' => ModePaiement::Cheque])
    );
    $miroir = $extourne->extourne;

    // Header PD
    expect($miroir->equilibree)->toBeTrue();
    expect($miroir->type_ecriture)->toBe('extourne');

    // Miroir : 601 C=300 + 401 D=300
    $ligne401Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $compte401->id)->firstOrFail();
    expect((float) $ligne401Miroir->debit)->toBe(300.0);
    expect((float) $ligne401Miroir->credit)->toBe(0.0);
    expect((int) $ligne401Miroir->tiers_id)->toBe((int) $this->tiers->id);

    $ligne601Miroir = TransactionLigne::where('transaction_id', $miroir->id)
        ->where('compte_id', $this->compte601->id)->firstOrFail();
    expect((float) $ligne601Miroir->debit)->toBe(0.0);
    expect((float) $ligne601Miroir->credit)->toBe(300.0);

    // T1.401C et miroir.401D ne sont PAS lettrées entre elles
    $ligne401T1->refresh();
    expect($ligne401T1->lettrage_code)->toBeNull('T1.401C reste ouverte');
    expect($ligne401Miroir->lettrage_code)->toBeNull('Miroir.401D non lettrée');

    // Grand livre 401 : D=300 (miroir) + C=300 (T1) → solde brut = 0
    $solde = (float) TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $this->tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
        ->value('solde');
    expect($solde)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Scénario J — Paranoïa assertEquilibre
// ---------------------------------------------------------------------------

it('[J] miroir PD est vérifié équilibré (assertEquilibre appelé)', function () {
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [['compte' => $this->compte706, 'montant' => 100.0]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Test paranoïa',
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

    // Verify independently that lines are balanced
    $ecritureGen = app(EcritureGenerator::class);
    $ecritureGen->assertEquilibre($lignesPD);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Scénario L — Ventilation multi-lignes
// ---------------------------------------------------------------------------

it('[L] extourne recette multi-ventilation — N lignes PD inversées correctement', function () {
    $compte707 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '707'],
        [
            'intitule' => 'Ventes de marchandises',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    // T1 : 411 D=350 + 706 C=200 + 707 C=150
    $t1 = $this->ecritureGen->pourRecetteACredit(
        tiers: $this->tiers,
        ventilations: [
            ['compte' => $this->compte706, 'montant' => 200.0],
            ['compte' => $compte707, 'montant' => 150.0],
        ],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Multi-ventilation',
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

    // 3 lignes PD : 411 C=350 + 706 D=200 + 707 D=150
    expect($lignesPD)->toHaveCount(3);

    $totalDebit = $lignesPD->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $lignesPD->sum(fn ($l) => (float) $l->credit);
    expect(bccomp((string) $totalDebit, (string) $totalCredit, 2))->toBe(0);

    // 706 inversée
    $l706 = $lignesPD->firstWhere('compte_id', (int) $this->compte706->id);
    expect((float) $l706->debit)->toBe(200.0);
    expect((float) $l706->credit)->toBe(0.0);

    // 707 inversée
    $l707 = $lignesPD->firstWhere('compte_id', (int) $compte707->id);
    expect((float) $l707->debit)->toBe(150.0);
    expect((float) $l707->credit)->toBe(0.0);

    // 411 inversée
    $compte411 = compteSysteme('411');
    $l411 = $lignesPD->firstWhere('compte_id', (int) $compte411->id);
    expect((float) $l411->debit)->toBe(0.0);
    expect((float) $l411->credit)->toBe(350.0);

    // Miroir 411 non lettrée (pas de cross-lettrage)
    expect($l411->lettrage_code)->toBeNull('Miroir 411 non lettrée');
});
