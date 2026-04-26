<?php

declare(strict_types=1);

use App\Livewire\Portail\FacturePartenaire\AtraiterIndex;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// ---------------------------------------------------------------------------
// Setup : deux associations (X et Y), deux tiers sur X, un homonyme sur Y
//
// Le global beforeEach (Pest.php) boot un tenant par défaut ; on le clear
// ici pour prendre le contrôle complet du contexte multi-tenant.
// ---------------------------------------------------------------------------

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');

    // --- Association X ---
    $this->assoX = Association::factory()->create(['slug' => 'asso-x']);
    TenantContext::boot($this->assoX);
    $this->tiersA = Tiers::factory()->pourDepenses()->create([
        'association_id' => $this->assoX->id,
    ]);
    $this->tiersB = Tiers::factory()->pourDepenses()->create([
        'association_id' => $this->assoX->id,
    ]);
    TenantContext::clear();

    // --- Association Y (cross-tenant) ---
    $this->assoY = Association::factory()->create(['slug' => 'asso-y']);
    TenantContext::boot($this->assoY);
    $this->tiersHomonyme = Tiers::factory()->pourDepenses()->create([
        'association_id' => $this->assoY->id,
        'email' => $this->tiersA->email, // même email = scénario homonyme
    ]);
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Cas 1 : Tiers A / Asso X ne voit pas les dépôts du Tiers B / Asso X
//          via la page /portail/factures (AtraiterIndex)
// ---------------------------------------------------------------------------

it('[isolation] Tiers A ne voit aucun dépôt soumis du Tiers B sur le même tenant', function () {
    TenantContext::boot($this->assoX);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'numero_facture' => 'FACT-B-001',
    ]);

    Auth::guard('tiers-portail')->login($this->tiersA);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->assoX->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-B-001');
});

// ---------------------------------------------------------------------------
// Cas 2 : Tiers A / Asso X ne voit pas les Transactions du Tiers B / Asso X
//          via /portail/historique (HistoriqueDepenses\Index)
// ---------------------------------------------------------------------------

it('[isolation] Tiers A ne voit aucune transaction de dépense du Tiers B sur le même tenant', function () {
    TenantContext::boot($this->assoX);

    Transaction::factory()->asDepense()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'numero_piece' => 'DEP-B-001',
    ]);
    // Sa propre transaction (contrôle positif)
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
        'numero_piece' => 'DEP-A-001',
    ]);

    Auth::guard('tiers-portail')->login($this->tiersA);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->assoX->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('DEP-A-001')
        ->assertDontSee('DEP-B-001');
});

// ---------------------------------------------------------------------------
// Cas 3 : Tiers A / Asso X ne peut télécharger le PDF d'un dépôt du Tiers B
//          via URL signée → 403
// ---------------------------------------------------------------------------

it('[isolation] Tiers A ne peut accéder au PDF d\'un dépôt du Tiers B via URL signée → 403', function () {
    TenantContext::boot($this->assoX);

    $pdfPath = "associations/{$this->assoX->id}/factures-deposees/2026/04/2026-04-01-fact-b-002-abc123.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $depotB = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-B-002',
    ]);

    Auth::guard('tiers-portail')->login($this->tiersA);
    session(['portail.last_activity_at' => now()->timestamp]);

    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->assoX->slug,
        'depot' => $depotB->id,
    ]);

    $this->get($signedUrl)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Cas 4 : Tiers homonyme sur Asso Y ne voit aucune donnée d'Asso X
//          (cross-tenant complet — TenantScope fail-closed)
// ---------------------------------------------------------------------------

it('[isolation] tiers homonyme Asso Y ne voit aucun dépôt d\'Asso X (cross-tenant)', function () {
    // Créer un dépôt appartenant à tiersA sur assoX
    TenantContext::boot($this->assoX);
    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
        'numero_facture' => 'FACT-ASSO-X-001',
    ]);
    TenantContext::clear();

    // Connexion en tant que tiersHomonyme sur assoY
    TenantContext::boot($this->assoY);
    Auth::guard('tiers-portail')->login($this->tiersHomonyme);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->assoY->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-ASSO-X-001');
});

// ---------------------------------------------------------------------------
// Cas 5 : Tiers homonyme sur Asso Y ne voit aucune transaction d'Asso X
//          via /portail/historique (cross-tenant)
// ---------------------------------------------------------------------------

it('[isolation] tiers homonyme Asso Y ne voit aucune transaction d\'Asso X (cross-tenant)', function () {
    // Créer une transaction appartenant à tiersA sur assoX
    TenantContext::boot($this->assoX);
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
        'numero_piece' => 'DEP-ASSO-X-001',
    ]);
    TenantContext::clear();

    // Connexion en tant que tiersHomonyme sur assoY
    TenantContext::boot($this->assoY);
    Auth::guard('tiers-portail')->login($this->tiersHomonyme);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->assoY->slug}/portail/historique")
        ->assertStatus(200)
        ->assertDontSee('DEP-ASSO-X-001');
});

// ---------------------------------------------------------------------------
// Cas 6 : Service oublier() refuse cross-tiers même tenant
//          (test feature bout-en-bout via composant AtraiterIndex)
// ---------------------------------------------------------------------------

it('[isolation] oublier() lève DomainException si Tiers A tente de supprimer le dépôt de Tiers B (feature bout-en-bout)', function () {
    TenantContext::boot($this->assoX);

    $depotB = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'pdf_path' => "associations/{$this->assoX->id}/factures-deposees/2026/04/2026-04-01-fact-b-003-def456.pdf",
        'numero_facture' => 'FACT-B-003',
    ]);

    Auth::guard('tiers-portail')->login($this->tiersA);

    $component = new AtraiterIndex;
    $component->mount($this->assoX);

    expect(fn () => $component->oublier((int) $depotB->id))
        ->toThrow(DomainException::class);

    // Le dépôt doit toujours exister en base
    expect(FacturePartenaireDeposee::find((int) $depotB->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 7 : URL signée générée pour Tiers B, utilisée par Tiers A → 403
//          (cross-tiers via URL signée volée — l'URL est valide mais le
//           contrôleur vérifie la propriété du dépôt)
// ---------------------------------------------------------------------------

it('[isolation] URL signée générée pour Tiers B refusée si présentée par Tiers A → 403', function () {
    TenantContext::boot($this->assoX);

    $pdfPath = "associations/{$this->assoX->id}/factures-deposees/2026/04/2026-04-01-fact-b-004-ghi789.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $depotB = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-B-004',
    ]);

    // L'URL est signée (valide cryptographiquement) mais générée pour depotB
    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->assoX->slug,
        'depot' => $depotB->id,
    ]);

    // Tiers A s'authentifie et utilise l'URL signée de Tiers B
    Auth::guard('tiers-portail')->login($this->tiersA);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get($signedUrl)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Cas 8 : Tiers A / Asso X tente d'accéder à un dépôt d'Asso X via URL
//          d'Asso Y — TenantScope l'exclut → 404
// ---------------------------------------------------------------------------

it('[isolation] dépôt Asso X inaccessible via slug Asso Y (TenantScope fail-closed) → 404', function () {
    // Créer un dépôt dans assoX
    TenantContext::boot($this->assoX);
    $pdfPath = "associations/{$this->assoX->id}/factures-deposees/2026/04/2026-04-01-fact-a-005-jkl012.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $depotA = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-A-005',
    ]);
    TenantContext::clear();

    // Connexion en tant que tiersHomonyme sur assoY
    TenantContext::boot($this->assoY);
    Auth::guard('tiers-portail')->login($this->tiersHomonyme);
    session(['portail.last_activity_at' => now()->timestamp]);

    // URL signée construite avec le slug d'assoY mais l'ID du dépôt d'assoX
    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->assoY->slug,
        'depot' => $depotA->id,
    ]);

    // TenantScope exclut le dépôt car association_id ≠ assoY → 404
    $this->get($signedUrl)->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Cas 9 : Tiers A ne voit pas les transactions de Tiers B via la route PDF
//          de l'historique → 403 (cross-tiers PDF historique)
// ---------------------------------------------------------------------------

it('[isolation] Tiers A ne peut accéder au PDF pièce jointe d\'une transaction de Tiers B → 403', function () {
    TenantContext::boot($this->assoX);

    $txB = Transaction::factory()->asDepense()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersB->id,
        'piece_jointe_path' => 'facture-b.pdf',
        'piece_jointe_nom' => 'Facture B.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $fullPath = $txB->pieceJointeFullPath();
    Storage::disk('local')->put($fullPath, '%PDF-1.4 fake content');

    Auth::guard('tiers-portail')->login($this->tiersA);
    session(['portail.last_activity_at' => now()->timestamp]);

    $signedUrl = URL::signedRoute('portail.historique.pdf', [
        'association' => $this->assoX->slug,
        'transaction' => $txB->id,
    ]);

    $this->get($signedUrl)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Cas 10 : Tiers B ne voit pas les NDF (notes de frais) du Tiers A
//           via /portail/notes-de-frais — isolation cross-tiers NDF
// ---------------------------------------------------------------------------

it('[isolation] Tiers B ne voit pas les NDF du Tiers A sur le même tenant', function () {
    TenantContext::boot($this->assoX);

    $txA = Transaction::factory()->asDepense()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
    ]);
    NoteDeFrais::factory()->create([
        'association_id' => $this->assoX->id,
        'tiers_id' => $this->tiersA->id,
        'transaction_id' => $txA->id,
        'libelle' => 'Libellé NDF secret du Tiers A',
    ]);

    Auth::guard('tiers-portail')->login($this->tiersB);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->assoX->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertDontSee('Libellé NDF secret du Tiers A');
});
