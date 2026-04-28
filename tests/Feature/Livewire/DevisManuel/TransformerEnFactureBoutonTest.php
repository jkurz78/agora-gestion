<?php

declare(strict_types=1);

use App\Livewire\DevisManuel\DevisEdit;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->adminUser = User::factory()->create();
    $this->adminUser->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->adminUser->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->adminUser);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'ACME SARL',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Table de visibilité ──────────────────────────────────────────────────────

it('bouton absent pour statut brouillon', function (): void {
    $devis = Devis::factory()->brouillon()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertDontSeeHtml('Transformer en facture');
});

it('bouton absent pour statut valide', function (): void {
    $devis = Devis::factory()->valide()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertDontSeeHtml('Transformer en facture');
});

it('bouton present et enabled pour statut accepte', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertSeeHtml('Transformer en facture');
});

it('bouton absent pour statut refuse', function (): void {
    $devis = Devis::factory()->refuse()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertDontSeeHtml('Transformer en facture');
});

it('bouton absent pour statut annule', function (): void {
    $devis = Devis::factory()->annule()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertDontSeeHtml('Transformer en facture');
});

// ── Devis déjà transformé : bouton disabled avec tooltip ────────────────────

it('bouton disabled avec tooltip si devis deja transforme', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    // Lier une facture au devis pour simuler un devis déjà transformé
    Facture::create([
        'association_id' => $this->association->id,
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'saisi_par' => $this->adminUser->id,
        'exercice' => 2025,
        'devis_id' => $devis->id,
    ]);

    $html = Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertSeeHtml('Transformer en facture')
        ->assertSeeHtml('Une facture issue de ce devis existe déjà')
        ->html();

    // Le bouton doit porter l'attribut disabled (HTML, pas masqué CSS)
    expect($html)->toContain('disabled');
    expect($html)->toContain('Une facture issue de ce devis existe déjà');
});

// ── Click → transformation et redirection ───────────────────────────────────

it('click transformerEnFacture redirige vers la facture creee', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->call('transformerEnFacture')
        ->assertRedirect(route('facturation.factures.show', Facture::where('devis_id', $devis->id)->firstOrFail()));

    // Une facture a bien été créée pour ce devis
    expect(Facture::where('devis_id', $devis->id)->exists())->toBeTrue();
});

// ── Protection race : devis déjà transformé au moment du click ──────────────

it('action sur devis deja transforme affiche erreur sans crash', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    // Simuler race : lier une facture au devis avant le click
    Facture::create([
        'association_id' => $this->association->id,
        'date' => now()->toDateString(),
        'statut' => 'brouillon',
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'saisi_par' => $this->adminUser->id,
        'exercice' => 2025,
        'devis_id' => $devis->id,
    ]);

    // L'action ne doit pas crasher le composant et doit afficher un message d'erreur
    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->call('transformerEnFacture')
        ->assertSeeHtml('existe déjà');

    // Pas de double facture
    expect(Facture::where('devis_id', $devis->id)->count())->toBe(1);
});

// ── wire:confirm Bootstrap ───────────────────────────────────────────────────

it('blade contient wire:confirm sur le bouton transformer', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $devis])
        ->assertSeeHtml('wire:confirm=');
});
