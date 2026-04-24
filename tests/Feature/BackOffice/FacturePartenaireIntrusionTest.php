<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\BackOffice\FacturePartenaire\Index;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Setup : deux associations (X et Y)
//
// - assoX : possède un comptable (acteur des tests) et un tiers source.
// - assoY : possède un tiers et un dépôt cible (les données à ne pas fuir).
//
// Chaque test boot assoY pour créer le dépôt, puis rebascule sur assoX
// avant d'exécuter l'action.
// ---------------------------------------------------------------------------

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');

    // --- Association X (le comptable attaquant) ---
    $this->assoX = Association::factory()->create(['slug' => 'asso-x-intrusion']);
    TenantContext::boot($this->assoX);
    session(['current_association_id' => $this->assoX->id]);

    $this->comptableX = User::factory()->create();
    $this->comptableX->associations()->attach($this->assoX->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $this->comptableX->update(['derniere_association_id' => $this->assoX->id]);

    TenantContext::clear();

    // --- Association Y (victime — ses données ne doivent pas fuir) ---
    $this->assoY = Association::factory()->create(['slug' => 'asso-y-victime']);
    TenantContext::boot($this->assoY);

    $this->tiersY = Tiers::factory()->pourDepenses()->create([
        'association_id' => $this->assoY->id,
    ]);

    $pdfPath = "associations/{$this->assoY->id}/factures-deposees/2026/04/fact-y-intrusion.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $this->depotY = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoY->id,
        'tiers_id' => $this->tiersY->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-Y-INTRUSION-001',
    ]);

    TenantContext::clear();

    // Rebascule sur assoX — contexte actif pour les tests
    TenantContext::boot($this->assoX);
    session(['current_association_id' => $this->assoX->id]);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Cas 1 — Liste back-office : le comptable de X ne voit pas les dépôts de Y
// ---------------------------------------------------------------------------

it('cross-tenant — la liste back-office masque les dépôts d\'une autre asso', function () {
    $this->actingAs($this->comptableX);

    Livewire::test(Index::class)
        ->set('onglet', 'toutes')
        ->assertDontSee('FACT-Y-INTRUSION-001');
});

// ---------------------------------------------------------------------------
// Cas 2 — PDF : URL signée construite pour un dépôt de Y → 404
// ---------------------------------------------------------------------------

it('cross-tenant — URL signée pour un dépôt de l\'asso Y renvoie 404 depuis le tenant X', function () {
    // L'URL signée pointe sur le dépôt de assoY mais la requête est faite
    // dans le contexte du tenant X → TenantScope exclut le dépôt → 404.
    $signedUrl = URL::signedRoute('back-office.factures-partenaires.pdf', [
        'depot' => $this->depotY->id,
    ]);

    $this->actingAs($this->comptableX)
        ->get($signedUrl)
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Cas 3 — Comptabiliser : action depuis le composant Index sur un dépôt de Y → 404
// ---------------------------------------------------------------------------

it('cross-tenant — comptabiliser un dépôt de l\'asso Y depuis le tenant X renvoie 404', function () {
    $this->actingAs($this->comptableX);

    Livewire::test(Index::class)
        ->call('comptabiliser', $this->depotY->id)
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Cas 4 — Rejeter : ouvrirRejet sur un dépôt de Y depuis X → 404
// ---------------------------------------------------------------------------

it('cross-tenant — ouvrirRejet sur un dépôt de l\'asso Y depuis le tenant X renvoie 404', function () {
    $this->actingAs($this->comptableX);

    Livewire::test(Index::class)
        ->call('ouvrirRejet', $this->depotY->id)
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Cas 5 — TransactionForm : dispatch de l'event avec un dépôt de Y depuis X
//          → pas de pré-remplissage, flash error, showForm reste false
// ---------------------------------------------------------------------------

it('cross-tenant — dispatch open-transaction-form-from-depot-facture avec un dépôt de l\'asso Y ne pré-remplit pas le formulaire', function () {
    $this->actingAs($this->comptableX);

    $test = Livewire::test(TransactionForm::class);
    $test->instance()->openFormFromDepotFacture($this->depotY->id);

    expect($test->instance()->showForm)->toBeFalse();
    expect($test->instance()->factureDeposeeId)->toBeNull();
    expect(session('error'))->toContain('introuvable');
});
