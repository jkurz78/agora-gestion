<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TiersQuickViewService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create([
        'email' => 'dupont@example.com',
        'telephone' => '0612345678',
    ]);
    $this->service = app(TiersQuickViewService::class);
    $this->exercice = 2025;
});

afterEach(function () {
    TenantContext::clear();
});

// ─── Helper: create a raw transaction + explicit lignes (no factory auto-lignes) ───

function makeDepense(int $tiersId, float $montant, string $date, ?SousCategorie $sc = null, ?Operation $op = null): Transaction
{
    $sc ??= SousCategorie::factory()->create();
    $tx = Transaction::forceCreate([
        'type' => TypeTransaction::Depense,
        'date' => $date,
        'libelle' => 'Test dépense',
        'montant_total' => $montant,
        'tiers_id' => $tiersId,
        'compte_id' => CompteBancaire::factory()->create()->id,
        'saisi_par' => User::factory()->create()->id,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $op?->id,
        'montant' => $montant,
    ]);

    return $tx;
}

function makeRecette(int $tiersId, float $montant, string $date, ?SousCategorie $sc = null, ?Operation $op = null): Transaction
{
    $sc ??= SousCategorie::factory()->create();
    $tx = Transaction::forceCreate([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Test recette',
        'montant_total' => $montant,
        'tiers_id' => $tiersId,
        'compte_id' => CompteBancaire::factory()->create()->id,
        'saisi_par' => User::factory()->create()->id,
    ]);
    TransactionLigne::forceCreate([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $op?->id,
        'montant' => $montant,
    ]);

    return $tx;
}

// ─── Section contact ──────────────────────────────────────────────────────────

describe('contact', function (): void {
    test('toujours présent avec email et telephone', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('contact')
            ->and($result['contact']['email'])->toBe('dupont@example.com')
            ->and($result['contact']['telephone'])->toBe('0612345678');
    });

    test('présent même si email et telephone sont null', function (): void {
        $tiers = Tiers::factory()->create(['email' => null, 'telephone' => null]);

        $result = $this->service->getSummary($tiers, $this->exercice);

        expect($result)->toHaveKey('contact')
            ->and($result['contact']['email'])->toBeNull()
            ->and($result['contact']['telephone'])->toBeNull();
    });
});

// ─── Section depenses ─────────────────────────────────────────────────────────

describe('depenses', function (): void {
    test('absente quand aucune dépense', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('depenses');
    });

    test('présente avec count et total corrects', function (): void {
        makeDepense($this->tiers->id, 100.00, '2025-10-01');
        makeDepense($this->tiers->id, 50.00, '2025-11-01');

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('depenses')
            ->and($result['depenses']['count'])->toBe(2)
            ->and((float) $result['depenses']['total'])->toBe(150.00);
    });

    test('par_operation regroupe par opération', function (): void {
        $op = Operation::factory()->create(['nom' => 'Yoga Adultes']);
        $sc = SousCategorie::factory()->create(['nom' => 'Inscription']);
        makeDepense($this->tiers->id, 80.00, '2025-10-01', $sc, $op);
        makeDepense($this->tiers->id, 40.00, '2025-12-01', $sc, $op);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['depenses']['par_operation'])->toHaveCount(1);
        $groupe = $result['depenses']['par_operation'][0];
        expect($groupe['operation_id'])->toBe($op->id)
            ->and($groupe['operation_nom'])->toBe('Yoga Adultes')
            ->and($groupe['sous_categorie'])->toBe('Inscription')
            ->and($groupe['count'])->toBe(2)
            ->and((float) $groupe['total'])->toBe(120.00);
    });

    test('exclut les dépenses en dehors de l\'exercice', function (): void {
        makeDepense($this->tiers->id, 200.00, '2024-05-01'); // exercice 2023
        makeDepense($this->tiers->id, 100.00, '2025-10-01'); // exercice 2025 ✓

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['depenses']['count'])->toBe(1)
            ->and((float) $result['depenses']['total'])->toBe(100.00);
    });

    test('exclut les dépenses soft-deletées', function (): void {
        $tx = makeDepense($this->tiers->id, 100.00, '2025-10-01');
        $tx->delete();

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('depenses');
    });

    test('exclut les transaction_lignes soft-deletées', function (): void {
        $tx = makeDepense($this->tiers->id, 100.00, '2025-10-01');
        // Soft-delete the ligne, not the transaction
        TransactionLigne::where('transaction_id', $tx->id)->first()->delete();

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('depenses');
    });

    test('exclut les dépenses d\'un autre tiers', function (): void {
        $autre = Tiers::factory()->create();
        makeDepense($autre->id, 100.00, '2025-10-01');

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('depenses');
    });
});

// ─── Section recettes (hors dons et cotisations) ──────────────────────────────

describe('recettes', function (): void {
    test('absente quand aucune recette ordinaire', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('recettes');
    });

    test('présente avec count et total pour recettes ordinaires', function (): void {
        $sc = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);
        makeRecette($this->tiers->id, 60.00, '2025-10-01', $sc);
        makeRecette($this->tiers->id, 40.00, '2025-11-01', $sc);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('recettes')
            ->and($result['recettes']['count'])->toBe(2)
            ->and((float) $result['recettes']['total'])->toBe(100.00);
    });

    test('exclut les recettes classifiées comme dons', function (): void {
        $scDon = SousCategorie::factory()->pourDons()->create();
        $scNormal = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);
        makeRecette($this->tiers->id, 50.00, '2025-10-01', $scDon);
        makeRecette($this->tiers->id, 30.00, '2025-11-01', $scNormal);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['recettes']['count'])->toBe(1)
            ->and((float) $result['recettes']['total'])->toBe(30.00);
    });

    test('exclut les recettes classifiées comme cotisations', function (): void {
        $scCot = SousCategorie::factory()->pourCotisations()->create();
        $scNormal = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);
        makeRecette($this->tiers->id, 120.00, '2025-10-01', $scCot);
        makeRecette($this->tiers->id, 80.00, '2025-11-01', $scNormal);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['recettes']['count'])->toBe(1)
            ->and((float) $result['recettes']['total'])->toBe(80.00);
    });

    test('absente si toutes les recettes sont dons ou cotisations', function (): void {
        $scDon = SousCategorie::factory()->pourDons()->create();
        $scCot = SousCategorie::factory()->pourCotisations()->create();
        makeRecette($this->tiers->id, 50.00, '2025-10-01', $scDon);
        makeRecette($this->tiers->id, 30.00, '2025-11-01', $scCot);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('recettes');
    });

    test('exclut les recettes en dehors de l\'exercice', function (): void {
        $sc = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);
        makeRecette($this->tiers->id, 100.00, '2024-03-01', $sc); // exercice 2023
        makeRecette($this->tiers->id, 50.00, '2025-10-01', $sc); // exercice 2025 ✓

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['recettes']['count'])->toBe(1)
            ->and((float) $result['recettes']['total'])->toBe(50.00);
    });
});

// ─── Section dons ─────────────────────────────────────────────────────────────

describe('dons', function (): void {
    test('absent quand aucun don', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('dons');
    });

    test('présent avec count et total corrects', function (): void {
        $scDon = SousCategorie::factory()->pourDons()->create();
        makeRecette($this->tiers->id, 100.00, '2025-10-01', $scDon);
        makeRecette($this->tiers->id, 50.00, '2025-12-01', $scDon);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('dons')
            ->and($result['dons']['count'])->toBe(2)
            ->and((float) $result['dons']['total'])->toBe(150.00);
    });

    test('exclut les dons hors exercice', function (): void {
        $scDon = SousCategorie::factory()->pourDons()->create();
        makeRecette($this->tiers->id, 200.00, '2024-01-01', $scDon); // hors exercice
        makeRecette($this->tiers->id, 100.00, '2025-10-01', $scDon); // exercice 2025 ✓

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['dons']['count'])->toBe(1)
            ->and((float) $result['dons']['total'])->toBe(100.00);
    });
});

// ─── Section cotisations ──────────────────────────────────────────────────────

describe('cotisations', function (): void {
    test('absente quand aucune cotisation', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('cotisations');
    });

    test('présente avec count et total corrects', function (): void {
        $scCot = SousCategorie::factory()->pourCotisations()->create();
        makeRecette($this->tiers->id, 75.00, '2025-10-01', $scCot);
        makeRecette($this->tiers->id, 75.00, '2026-01-15', $scCot);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('cotisations')
            ->and($result['cotisations']['count'])->toBe(2)
            ->and((float) $result['cotisations']['total'])->toBe(150.00);
    });

    test('exclut les cotisations hors exercice', function (): void {
        $scCot = SousCategorie::factory()->pourCotisations()->create();
        makeRecette($this->tiers->id, 90.00, '2024-06-01', $scCot); // hors exercice
        makeRecette($this->tiers->id, 90.00, '2025-10-01', $scCot); // exercice 2025 ✓

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['cotisations']['count'])->toBe(1)
            ->and((float) $result['cotisations']['total'])->toBe(90.00);
    });
});

// ─── Section participations ───────────────────────────────────────────────────

describe('participations', function (): void {
    test('absente quand aucune participation', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('participations');
    });

    test('présente avec les opérations auxquelles le tiers participe', function (): void {
        $op1 = Operation::factory()->create(['nom' => 'Yoga', 'date_debut' => '2025-10-01']);
        $op2 = Operation::factory()->create(['nom' => 'Pilates', 'date_debut' => '2025-11-01']);
        Participant::create(['tiers_id' => $this->tiers->id, 'operation_id' => $op1->id]);
        Participant::create(['tiers_id' => $this->tiers->id, 'operation_id' => $op2->id]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('participations')
            ->and($result['participations'])->toHaveCount(2);

        $noms = array_column($result['participations'], 'operation_nom');
        expect($noms)->toContain('Yoga')
            ->and($noms)->toContain('Pilates');
    });

    test('chaque participation contient operation_id, operation_nom, date_debut', function (): void {
        $op = Operation::factory()->create(['nom' => 'Tai-Chi', 'date_debut' => '2025-09-15']);
        Participant::create(['tiers_id' => $this->tiers->id, 'operation_id' => $op->id]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        $participation = $result['participations'][0];
        expect($participation)->toHaveKey('operation_id')
            ->and($participation)->toHaveKey('operation_nom')
            ->and($participation)->toHaveKey('date_debut')
            ->and($participation['operation_id'])->toBe($op->id)
            ->and($participation['operation_nom'])->toBe('Tai-Chi');
    });

    test('n\'inclut pas les participations d\'un autre tiers', function (): void {
        $autre = Tiers::factory()->create();
        $op = Operation::factory()->create();
        Participant::create(['tiers_id' => $autre->id, 'operation_id' => $op->id]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('participations');
    });
});

// ─── Section referent (données sensibles) ────────────────────────────────────

describe('referent', function (): void {
    test('absente quand l\'utilisateur ne peut pas voir les données sensibles', function (): void {
        $op = Operation::factory()->create(['date_debut' => '2025-10-01', 'date_fin' => '2026-06-30']);
        $participant = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Curie']);
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'refere_par_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('referent');
    });

    test('présente quand l\'utilisateur peut voir les données sensibles', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);
        $op = Operation::factory()->create(['date_debut' => '2025-10-01', 'date_fin' => '2026-06-30']);
        $participant = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Curie']);
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'refere_par_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('referent');
    });

    test('referent contient refere_par avec les participants référés', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);
        $op = Operation::factory()->create(['nom' => 'Yoga', 'date_debut' => '2025-10-01', 'date_fin' => '2026-06-30']);
        $participant = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Curie']);
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'refere_par_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['referent'])->toHaveKey('refere_par')
            ->and($result['referent']['refere_par'])->toHaveCount(1)
            ->and($result['referent']['refere_par'][0]['operation'])->toBe('Yoga');
    });

    test('referent contient medecin si ce tiers est médecin de participants', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);
        $op = Operation::factory()->create(['date_debut' => '2025-10-01', 'date_fin' => '2026-06-30']);
        $participant = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'medecin_tiers_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['referent'])->toHaveKey('medecin')
            ->and($result['referent']['medecin'])->toHaveCount(1);
    });

    test('referent contient therapeute si ce tiers est thérapeute de participants', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);
        $op = Operation::factory()->create(['date_debut' => '2025-10-01', 'date_fin' => '2026-06-30']);
        $participant = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Lucie']);
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'therapeute_tiers_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['referent'])->toHaveKey('therapeute')
            ->and($result['referent']['therapeute'])->toHaveCount(1);
    });

    test('exclut les referents d\'opérations hors exercice', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);
        $op = Operation::factory()->create(['date_debut' => '2024-01-01', 'date_fin' => '2024-06-30']);
        $participant = Tiers::factory()->create();
        Participant::create([
            'tiers_id' => $participant->id,
            'operation_id' => $op->id,
            'refere_par_id' => $this->tiers->id,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('referent');
    });

    test('absente si l\'utilisateur a peut_voir_donnees_sensibles mais aucune donnée sensible', function (): void {
        $this->user->update(['peut_voir_donnees_sensibles' => true]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('referent');
    });
});

// ─── Section factures ─────────────────────────────────────────────────────────

describe('factures', function (): void {
    test('absente quand aucune facture', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('factures');
    });

    test('exclut les factures brouillon', function (): void {
        Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Brouillon,
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 100.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('factures');
    });

    test('présente pour une facture validée', function (): void {
        Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 200.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('factures')
            ->and($result['factures']['count'])->toBe(1)
            ->and((float) $result['factures']['total'])->toBe(200.00);
    });

    test('compte les factures impayées (validées non acquittées)', function (): void {
        Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 300.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['factures']['impayees'])->toBe(1);
    });

    test('impayees = 0 si toutes les factures validées sont acquittées', function (): void {
        $compte = CompteBancaire::factory()->create(['est_systeme' => false]);
        $facture = Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0001',
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 100.00,
        ]);
        // Attach a transaction matching the full amount so isAcquittee() = true
        $tx = Transaction::forceCreate([
            'type' => TypeTransaction::Recette,
            'date' => '2025-10-05',
            'libelle' => 'Règlement facture',
            'montant_total' => 100.00,
            'tiers_id' => $this->tiers->id,
            'compte_id' => $compte->id,
            'saisi_par' => $this->user->id,
            'statut_reglement' => 'recu', // v3: montantRegle() requires statut_reglement in ('recu','pointe')
        ]);
        $facture->transactions()->attach($tx->id);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result['factures']['impayees'])->toBe(0);
    });

    test('exclut les factures annulées du count et total', function (): void {
        Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Annulee,
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 500.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('factures');
    });

    test('exclut les factures d\'un autre tiers', function (): void {
        $autre = Tiers::factory()->create();
        Facture::forceCreate([
            'tiers_id' => $autre->id,
            'exercice' => $this->exercice,
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2025-0099',
            'date' => '2025-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 100.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('factures');
    });

    test('exclut les factures d\'un autre exercice', function (): void {
        Facture::forceCreate([
            'tiers_id' => $this->tiers->id,
            'exercice' => 2024,
            'statut' => StatutFacture::Validee,
            'numero' => 'FA-2024-0001',
            'date' => '2024-10-01',
            'saisi_par' => $this->user->id,
            'montant_total' => 100.00,
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('factures');
    });
});

// ─── Cas intégration : résumé complet ─────────────────────────────────────────

describe('résumé complet', function (): void {
    test('retourne uniquement les clés avec données', function (): void {
        // Dépense
        makeDepense($this->tiers->id, 100.00, '2025-10-01');
        // Don
        $scDon = SousCategorie::factory()->pourDons()->create();
        makeRecette($this->tiers->id, 50.00, '2025-10-15', $scDon);
        // Participation
        $op = Operation::factory()->create();
        Participant::create(['tiers_id' => $this->tiers->id, 'operation_id' => $op->id]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('contact')
            ->and($result)->toHaveKey('depenses')
            ->and($result)->toHaveKey('dons')
            ->and($result)->toHaveKey('participations')
            ->and($result)->not->toHaveKey('recettes')
            ->and($result)->not->toHaveKey('cotisations')
            ->and($result)->not->toHaveKey('referent')
            ->and($result)->not->toHaveKey('factures');
    });
});
