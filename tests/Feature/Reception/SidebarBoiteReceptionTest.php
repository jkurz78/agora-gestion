<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->user->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// (a) Entrée "Boîte de réception" présente et placée avant le premier accordion group
it('affiche une entrée top-level "Boîte de réception" avant les groupes accordéon', function (): void {
    $response = $this->actingAs($this->user)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSeeInOrder([
        'Boîte de réception',
        'data-bs-toggle="collapse"',
    ]);
});

// (b) Le href de l'entrée pointe sur route('facturation.documents-en-attente')
it('href de l\'entrée pointe sur route facturation.documents-en-attente', function (): void {
    $response = $this->actingAs($this->user)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSeeHtml(route('facturation.documents-en-attente'));
});

// (c) Le badge affiche le compteur quand incomingDocumentsCount > 0
it('affiche un badge avec le nombre de documents en attente quand > 0', function (): void {
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'sender_email' => 'test@example.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $response = $this->actingAs($this->user)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSeeInOrder(['Boîte de réception', '1']);
});

// (d) Le badge est absent quand incomingDocumentsCount = 0
it('n\'affiche pas de badge quand aucun document en attente', function (): void {
    // Ensure no incoming documents exist for this association
    IncomingDocument::where('association_id', $this->association->id)->delete();

    $response = $this->actingAs($this->user)->get(route('comptabilite.transactions'));

    $response->assertOk();
    // The badge must appear only near "Boîte de réception" — when count=0 it should not be present
    // We check that there's no badge adjacent to the inbox entry (no badge at all for count=0)
    $html = $response->getContent();
    // Extract the top-level inbox link section and verify no badge
    $pattern = '/Boîte de réception.*?<\/a>/s';
    preg_match($pattern, (string) $html, $matches);
    expect($matches[0] ?? '')->not->toContain('badge bg-warning');
});

// (e) L'ancienne entrée "Documents en attente" sous Facturation n'est plus dans le DOM
it('l\'ancienne entrée "Documents en attente" sous Facturation n\'est plus présente', function (): void {
    $response = $this->actingAs($this->user)->get(route('comptabilite.transactions'));

    $response->assertOk();

    // The old label "Documents en attente" should no longer appear in sidebar
    $response->assertDontSee('Documents en attente');
});
