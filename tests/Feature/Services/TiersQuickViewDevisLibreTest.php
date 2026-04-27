<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\TiersQuickViewService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(TiersQuickViewService::class);
    $this->exercice = 2025;
});

afterEach(function (): void {
    TenantContext::clear();
});

describe('devis_libres dans getSummary', function (): void {

    // ─── Test 1 : structure de la clé devis_libres ───────────────────────────

    test('getSummary retourne la clé devis_libres avec counts et total_acceptes', function (): void {
        Devis::factory()->brouillon()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '0.00',
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->toHaveKey('devis_libres')
            ->and($result['devis_libres'])->toHaveKey('counts')
            ->and($result['devis_libres'])->toHaveKey('total_acceptes')
            ->and($result['devis_libres']['counts'])->toBeArray();
    });

    // ─── Test 2 : counts et total_acceptes corrects ───────────────────────────

    test('counts par statut et total_acceptes sont corrects avec mix de devis', function (): void {
        // 1 brouillon
        Devis::factory()->brouillon()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '0.00',
        ]);

        // 2 validés
        Devis::factory()->valide()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '500.00',
        ]);
        Devis::factory()->valide()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '200.00',
        ]);

        // 1 accepté à 1500 €
        Devis::factory()->accepte()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '1500.00',
        ]);

        // 1 refusé
        Devis::factory()->refuse()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '300.00',
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);
        $dl = $result['devis_libres'];

        expect($dl['counts'])->toHaveKey(StatutDevis::Brouillon->value)
            ->and($dl['counts'][StatutDevis::Brouillon->value])->toBe(1)
            ->and($dl['counts'][StatutDevis::Valide->value])->toBe(2)
            ->and($dl['counts'][StatutDevis::Accepte->value])->toBe(1)
            ->and($dl['counts'][StatutDevis::Refuse->value])->toBe(1)
            ->and((float) $dl['total_acceptes'])->toBe(1500.00);
    });

    // ─── Test 3 : isolation par tiers (ne voit que les devis du tiers demandé) ─

    test('ne comptabilise que les devis du tiers demandé', function (): void {
        $autreTiers = Tiers::factory()->create();

        // Devis du bon tiers
        Devis::factory()->accepte()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '800.00',
        ]);

        // Devis d'un autre tiers (ne doit pas apparaître)
        Devis::factory()->accepte()->create([
            'tiers_id' => $autreTiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '9999.00',
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);
        $dl = $result['devis_libres'];

        expect($dl['counts'][StatutDevis::Accepte->value])->toBe(1)
            ->and((float) $dl['total_acceptes'])->toBe(800.00);
    });

    // ─── Test 4 : les annulés sont comptés séparément dans counts ─────────────

    test('les annulés sont comptés dans counts[annule] mais exclus du total_acceptes', function (): void {
        Devis::factory()->annule()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '600.00',
        ]);

        Devis::factory()->accepte()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '400.00',
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);
        $dl = $result['devis_libres'];

        expect($dl['counts'][StatutDevis::Annule->value])->toBe(1)
            ->and($dl['counts'][StatutDevis::Accepte->value])->toBe(1)
            ->and((float) $dl['total_acceptes'])->toBe(400.00);
    });

    // ─── Test 5 : pas de N+1 — delta ≤ 2 queries pour la section devis_libres ─

    test('getSummary avec beaucoup de devis n\'émet pas plus de 2 queries supplémentaires pour devis_libres', function (): void {
        // Baseline : call sans aucun devis pour mesurer le coût des autres sections
        DB::enableQueryLog();
        $this->service->getSummary($this->tiers, $this->exercice);
        $baselineCount = count(DB::getQueryLog());

        // Créer 100 devis pour le tiers avec des numéros uniques (statuts avec numero)
        Devis::factory()->count(20)->brouillon()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '0.00',
        ]);
        foreach (range(1, 20) as $i) {
            Devis::factory()->state([
                'tiers_id' => $this->tiers->id,
                'exercice' => $this->exercice,
                'statut' => StatutDevis::Valide,
                'numero' => 'D-'.$this->exercice.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'montant_total' => '100.00',
            ])->create();
        }
        foreach (range(21, 40) as $i) {
            Devis::factory()->state([
                'tiers_id' => $this->tiers->id,
                'exercice' => $this->exercice,
                'statut' => StatutDevis::Accepte,
                'numero' => 'D-'.$this->exercice.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'montant_total' => '200.00',
            ])->create();
        }
        foreach (range(41, 60) as $i) {
            Devis::factory()->state([
                'tiers_id' => $this->tiers->id,
                'exercice' => $this->exercice,
                'statut' => StatutDevis::Refuse,
                'numero' => 'D-'.$this->exercice.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'montant_total' => '150.00',
            ])->create();
        }
        Devis::factory()->count(20)->annule()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '50.00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->getSummary($this->tiers, $this->exercice);
        $afterCount = count(DB::getQueryLog());

        // The delta should not grow with N devis; the devis_libres aggregation must use ≤ 2 queries
        // We compare: total queries with 100 devis ≤ baseline + 2
        expect($afterCount)->toBeLessThanOrEqual($baselineCount + 2);
    });

    // ─── Test 6 : isolation multi-tenant ─────────────────────────────────────

    test('les devis d\'une autre association ne sont pas comptabilisés', function (): void {
        // Association et tiers dans une autre asso
        $autreAsso = Association::factory()->create();
        $autreTiers = Tiers::factory()->create(); // créé dans asso courante par TenantContext
        // On crée un devis directement pour une autre asso
        $autreUser = User::factory()->create();
        Devis::forceCreate([
            'association_id' => $autreAsso->id,
            'tiers_id' => $autreTiers->id,
            'date_emission' => '2025-10-01',
            'date_validite' => '2025-10-31',
            'statut' => StatutDevis::Accepte,
            'montant_total' => '9999.00',
            'exercice' => $this->exercice,
            'saisi_par_user_id' => $autreUser->id,
        ]);

        // Devis de la bonne asso et du bon tiers
        Devis::factory()->accepte()->create([
            'tiers_id' => $this->tiers->id,
            'exercice' => $this->exercice,
            'montant_total' => '100.00',
        ]);

        $result = $this->service->getSummary($this->tiers, $this->exercice);
        $dl = $result['devis_libres'];

        expect($dl['counts'][StatutDevis::Accepte->value])->toBe(1)
            ->and((float) $dl['total_acceptes'])->toBe(100.00);
    });

    // ─── Test 7 : absent si aucun devis ──────────────────────────────────────

    test('la clé devis_libres est absente si aucun devis existe pour le tiers', function (): void {
        $result = $this->service->getSummary($this->tiers, $this->exercice);

        expect($result)->not->toHaveKey('devis_libres');
    });

});
