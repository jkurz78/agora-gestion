<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);

    // Tiers for creating depots
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);

    // Admin user attached to the tenant
    $this->adminUser = User::factory()->create();
    $this->adminUser->associations()->attach($this->asso->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Scénario 1 : Admin authentifié → 200 + application/pdf
// ---------------------------------------------------------------------------

it('[pdf-bo] Admin authentifié obtient le PDF → 200', function () {
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/fact-001.pdf";

    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-001',
    ]);

    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    $response = $this->actingAs($this->adminUser)->get($url);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))
        ->toStartWith('inline')
        ->toContain('Facture ')
        ->toContain($depot->numero_facture);
});

it('[pdf-bo] Comptable authentifié obtient le PDF → 200', function () {
    $comptable = User::factory()->create();
    $comptable->associations()->attach($this->asso->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);

    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/fact-002.pdf";

    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-002',
    ]);

    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    $this->actingAs($comptable)
        ->get($url)
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

// ---------------------------------------------------------------------------
// Scénario 2 : Non authentifié → redirect vers login
// ---------------------------------------------------------------------------

it('[pdf-bo] Utilisateur non authentifié → redirigé vers login', function () {
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/fact-003.pdf";

    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-003',
    ]);

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    $this->get($url)->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Scénario 3 : Admin de tenant X accède à un dépôt de tenant Y → 404 (TenantScope)
// ---------------------------------------------------------------------------

it('[pdf-bo] Admin tenant X accède à dépôt tenant Y → 404 (TenantScope fail-closed)', function () {
    $assoB = Association::factory()->create();

    // Create depot in asso A (current tenant context)
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/fact-004.pdf";
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-004',
    ]);
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    // Switch context to asso B, create an admin user for asso B
    TenantContext::clear();
    TenantContext::boot($assoB);
    session(['current_association_id' => $assoB->id]);

    $userB = User::factory()->create();
    $userB->associations()->attach($assoB->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    // TenantScope on asso B cannot find depot from asso A → 404
    $this->actingAs($userB)
        ->get($url)
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Scénario 4 : Utilisateur Gestionnaire → 403 (policy refuse)
// ---------------------------------------------------------------------------

it('[pdf-bo] Gestionnaire → 403 (policy::treat refuse)', function () {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->asso->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);

    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/fact-005.pdf";

    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-005',
    ]);

    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    $this->actingAs($gestionnaire)
        ->get($url)
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scénario 5 : Fichier PDF absent du disque → 404
// ---------------------------------------------------------------------------

it('[pdf-bo] renvoie 404 si le fichier PDF est absent du disque', function (): void {
    // Storage::fake() is empty — no file is stored
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => 'associations/'.$this->asso->id.'/factures-deposees/2026/04/missing.pdf',
        'numero_facture' => 'FACT-MISSING',
    ]);

    $url = route('back-office.factures-partenaires.pdf', ['depot' => $depot->id]);

    $this->actingAs($this->adminUser)->get($url)->assertNotFound();
});
