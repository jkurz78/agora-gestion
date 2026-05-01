<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->association->update(['facture_compte_bancaire_id' => $this->compte->id]);

    $this->comptable = User::factory()->create();
    $this->comptable->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $this->comptable->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    $this->actingAs($this->comptable);

    $this->service = app(FactureService::class);
    $this->exerciceCourant = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helper : crée une facture validée avec 1 ligne MontantManuel ─────────────

/**
 * Crée et valide une facture portant 1 ligne MontantManuel.
 * Bypasse les guards de valider() (mode_paiement_prevu + sous_categorie_id) en
 * injectant directement en DB, puis appelle valider() qui génère la TX.
 *
 * Pour les besoins du test de concurrence, on bypasse valider() et on crée
 * directement la facture en statut Validee (sans lignes MontantManuel) pour
 * éviter la complexité de la génération de TX — ce test porte uniquement sur
 * la séquence des numéros d'avoir.
 */
function concurrenceCreerFactureValidee(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    CompteBancaire $compte,
    int $exercice,
    string $suffixNumero,
): Facture {
    $facture = new Facture([
        'numero' => 'F-'.$exercice.'-'.$suffixNumero,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiers->id,
        'montant_total' => 100.0,
        'exercice' => $exercice,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
        'saisi_par' => auth()->id(),
    ]);
    // Doit hériter de association_id via TenantModel — on utilise create() normal
    $facture->save();

    return $facture->fresh();
}

// ─── AC-12 : 2 annulations consécutives produisent des numéros distincts ───────

test('2 annulations consecutives produisent des numeros avoir sequentiels et distincts', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();
    $exercice = $this->exerciceCourant;

    // Créer 2 factures validées distinctes dans le même exercice
    $f1 = concurrenceCreerFactureValidee(
        $this->service, $tiers, $sousCategorie, $this->compte, $exercice, '0010',
    );
    $f2 = concurrenceCreerFactureValidee(
        $this->service, $tiers, $sousCategorie, $this->compte, $exercice, '0011',
    );

    expect($f1->statut)->toBe(StatutFacture::Validee);
    expect($f2->statut)->toBe(StatutFacture::Validee);

    // ── Action : annuler les 2 séquentiellement ────────────────────────────────

    $this->service->annuler($f1);
    $this->service->annuler($f2);

    // ── Assert : numéros distincts et séquentiels ─────────────────────────────

    $numeroAvoir1 = $f1->fresh()->numero_avoir;
    $numeroAvoir2 = $f2->fresh()->numero_avoir;

    $expectedAv1 = sprintf('AV-%d-0001', $exercice);
    $expectedAv2 = sprintf('AV-%d-0002', $exercice);

    expect($numeroAvoir1)->toBe($expectedAv1);
    expect($numeroAvoir2)->toBe($expectedAv2);

    // Unicité stricte
    expect($numeroAvoir1)->not->toBe($numeroAvoir2);
});
