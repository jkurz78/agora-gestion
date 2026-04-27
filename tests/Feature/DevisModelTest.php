<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// ─── StatutDevis enum helpers ───────────────────────────────────────────────

it('StatutDevis::Brouillon->peutEtreModifie() returns true', function (): void {
    expect(StatutDevis::Brouillon->peutEtreModifie())->toBeTrue();
});

it('StatutDevis::Valide->peutEtreModifie() returns true', function (): void {
    expect(StatutDevis::Valide->peutEtreModifie())->toBeTrue();
});

it('StatutDevis::Accepte->peutEtreModifie() returns false', function (): void {
    expect(StatutDevis::Accepte->peutEtreModifie())->toBeFalse();
});

it('StatutDevis::Refuse->peutEtreModifie() returns false', function (): void {
    expect(StatutDevis::Refuse->peutEtreModifie())->toBeFalse();
});

it('StatutDevis::Annule->peutEtreModifie() returns false', function (): void {
    expect(StatutDevis::Annule->peutEtreModifie())->toBeFalse();
});

it('StatutDevis::Brouillon->peutPasserEnvoye() returns true', function (): void {
    expect(StatutDevis::Brouillon->peutPasserEnvoye())->toBeTrue();
});

it('StatutDevis::Valide->peutPasserEnvoye() returns false', function (): void {
    expect(StatutDevis::Valide->peutPasserEnvoye())->toBeFalse();
});

it('StatutDevis::all cases peutEtreDuplique() returns true', function (): void {
    foreach (StatutDevis::cases() as $case) {
        expect($case->peutEtreDuplique())->toBeTrue("Expected {$case->value} to be duplicable");
    }
});

it('StatutDevis::Annule->peutEtreAnnule() returns false', function (): void {
    expect(StatutDevis::Annule->peutEtreAnnule())->toBeFalse();
});

it('StatutDevis non-Annule cases peutEtreAnnule() returns true', function (): void {
    $cases = [StatutDevis::Brouillon, StatutDevis::Valide, StatutDevis::Accepte, StatutDevis::Refuse];
    foreach ($cases as $case) {
        expect($case->peutEtreAnnule())->toBeTrue("Expected {$case->value} to be cancelable");
    }
});

it('StatutDevis::label() returns French labels', function (): void {
    expect(StatutDevis::Brouillon->label())->toBe('Brouillon')
        ->and(StatutDevis::Valide->label())->toBe('Validé')
        ->and(StatutDevis::Accepte->label())->toBe('Accepté')
        ->and(StatutDevis::Refuse->label())->toBe('Refusé')
        ->and(StatutDevis::Annule->label())->toBe('Annulé');
});

// ─── Devis factory + model ───────────────────────────────────────────────────

it('Devis::factory()->create() produces a valid model', function (): void {
    $tiers = Tiers::factory()->create();

    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);

    expect($devis->id)->toBeInt()
        ->and($devis->association_id)->toBeInt()
        ->and($devis->tiers_id)->toBe((int) $tiers->id)
        ->and($devis->statut)->toBe(StatutDevis::Brouillon)
        ->and($devis->date_emission)->not->toBeNull()
        ->and($devis->date_validite)->not->toBeNull()
        ->and((float) $devis->montant_total)->toBe(0.0)
        ->and($devis->exercice)->toBeInt()
        ->and($devis->deleted_at)->toBeNull();
});

it('Devis has correct date casts', function (): void {
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);

    expect($devis->date_emission)->toBeInstanceOf(Carbon::class)
        ->and($devis->date_validite)->toBeInstanceOf(Carbon::class);
});

it('Devis is tenant-scoped: association A cannot see devis of association B', function (): void {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $tiersA = Tiers::factory()->create();
    $devisA = Devis::factory()->create(['tiers_id' => $tiersA->id]);

    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create();
    $devisB = Devis::factory()->create(['tiers_id' => $tiersB->id]);

    // From assoA context, only assoA devis visible
    TenantContext::boot($assoA);
    expect(Devis::count())->toBe(1)
        ->and((int) Devis::first()->id)->toBe((int) $devisA->id);

    // From assoB context, only assoB devis visible
    TenantContext::boot($assoB);
    expect(Devis::count())->toBe(1)
        ->and((int) Devis::first()->id)->toBe((int) $devisB->id);
});

it('Devis auto-fills association_id from TenantContext on create', function (): void {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);

    expect((int) $devis->association_id)->toBe((int) $asso->id);
});

// ─── DevisLigne ─────────────────────────────────────────────────────────────

it('DevisLigne belongs to Devis', function (): void {
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);

    $ligne = DevisLigne::factory()->create(['devis_id' => $devis->id]);

    expect((int) $ligne->devis_id)->toBe((int) $devis->id)
        ->and($ligne->devis)->toBeInstanceOf(Devis::class);
});

it('DevisLigne cascade deletes when Devis is force-deleted', function (): void {
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);
    DevisLigne::factory()->create(['devis_id' => $devis->id]);
    DevisLigne::factory()->create(['devis_id' => $devis->id]);

    expect(DevisLigne::where('devis_id', $devis->id)->count())->toBe(2);

    $devis->forceDelete();

    expect(DevisLigne::where('devis_id', $devis->id)->count())->toBe(0);
});

it('Devis lignes() relation returns lines ordered by ordre', function (): void {
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);

    DevisLigne::factory()->create(['devis_id' => $devis->id, 'ordre' => 3]);
    DevisLigne::factory()->create(['devis_id' => $devis->id, 'ordre' => 1]);
    DevisLigne::factory()->create(['devis_id' => $devis->id, 'ordre' => 2]);

    $ordres = $devis->lignes->pluck('ordre')->all();
    expect($ordres)->toBe([1, 2, 3]);
});

it('DevisLigne has no timestamps', function (): void {
    $tiers = Tiers::factory()->create();
    $devis = Devis::factory()->create(['tiers_id' => $tiers->id]);
    $ligne = DevisLigne::factory()->create(['devis_id' => $devis->id]);

    // DevisLigne timestamps property should be false — no created_at/updated_at columns
    $ligneFromDb = DB::table('devis_lignes')->where('id', $ligne->id)->first();
    expect(isset($ligneFromDb->created_at))->toBeFalse();
});

// ─── Association.devis_validite_jours ────────────────────────────────────────

it('Association.devis_validite_jours column exists and defaults to 30', function (): void {
    $asso = Association::factory()->create();

    // Column should exist and have default value 30
    $raw = DB::table('association')
        ->where('id', $asso->id)
        ->value('devis_validite_jours');

    expect((int) $raw)->toBe(30);
});

it('Association model can update devis_validite_jours', function (): void {
    $asso = Association::factory()->create();
    $asso->update(['devis_validite_jours' => 45]);
    $asso->refresh();

    expect((int) $asso->devis_validite_jours)->toBe(45);
});
