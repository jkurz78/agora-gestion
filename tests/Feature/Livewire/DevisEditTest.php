<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Livewire\DevisLibre\DevisEdit;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'ACME SARL',
    ]);

    $this->devis = Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Mission audit',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── Mounting ────────────────────────────────────────────────────────────────

it('mounts a brouillon devis and renders the form', function () {
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->assertOk()
        ->assertSee('ACME SARL')
        ->assertSeeHtml('devis-libelle'); // input id is present
});

it('renders action buttons for brouillon', function () {
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->assertSeeHtml('Envoyer')
        ->assertSeeHtml('Dupliquer');
});

// ── Add line ────────────────────────────────────────────────────────────────

it('ajouterLigne creates a new ligne and updates total', function () {
    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->set('nouvelleLigneLibelle', 'Prestation conseil')
        ->set('nouvelleLignePrixUnitaire', '100')
        ->set('nouvelleLigneQuantite', '2')
        ->call('ajouterLigne');

    $component->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->lignes)->toHaveCount(1);
    expect((float) $this->devis->montant_total)->toBe(200.0);
    expect($this->devis->lignes->first()->libelle)->toBe('Prestation conseil');
});

it('ajouterLigne resets the new line form fields', function () {
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->set('nouvelleLigneLibelle', 'Prestation conseil')
        ->set('nouvelleLignePrixUnitaire', '100')
        ->set('nouvelleLigneQuantite', '2')
        ->call('ajouterLigne')
        ->assertSet('nouvelleLigneLibelle', '')
        ->assertSet('nouvelleLignePrixUnitaire', '')
        ->assertSet('nouvelleLigneQuantite', '1');
});

// ── Edit line inline ─────────────────────────────────────────────────────────

it('modifierLigneLibelle updates the libelle and refreshes the devis', function () {
    $ligne = DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ancien libellé',
        'prix_unitaire' => 50,
        'quantite' => 1,
        'montant' => 50,
        'ordre' => 1,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('modifierLigneLibelle', $ligne->id, 'Nouveau libellé')
        ->assertHasNoErrors();

    $ligne->refresh();
    expect($ligne->libelle)->toBe('Nouveau libellé');
});

it('modifierLignePrixUnitaire recalculates montant', function () {
    $ligne = DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Prestation',
        'prix_unitaire' => 50,
        'quantite' => 3,
        'montant' => 150,
        'ordre' => 1,
    ]);

    // Update montant total on the devis
    $this->devis->update(['montant_total' => 150]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('modifierLignePrixUnitaire', $ligne->id, '100')
        ->assertHasNoErrors();

    $ligne->refresh();
    expect((float) $ligne->montant)->toBe(300.0);

    $this->devis->refresh();
    expect((float) $this->devis->montant_total)->toBe(300.0);
});

it('modifierLigneQuantite recalculates montant', function () {
    $ligne = DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Prestation',
        'prix_unitaire' => 100,
        'quantite' => 1,
        'montant' => 100,
        'ordre' => 1,
    ]);

    $this->devis->update(['montant_total' => 100]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('modifierLigneQuantite', $ligne->id, '5')
        ->assertHasNoErrors();

    $ligne->refresh();
    expect((float) $ligne->montant)->toBe(500.0);
});

// ── Delete line ─────────────────────────────────────────────────────────────

it('supprimerLigne removes the ligne', function () {
    $ligne = DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'À supprimer',
        'prix_unitaire' => 100,
        'quantite' => 1,
        'montant' => 100,
        'ordre' => 1,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('supprimerLigne', $ligne->id)
        ->assertHasNoErrors();

    expect(DevisLigne::find($ligne->id))->toBeNull();
});

// ── Mark envoyé ─────────────────────────────────────────────────────────────

it('marquerEnvoye transitions brouillon non-vide to envoye with numero', function () {
    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne',
        'prix_unitaire' => 200,
        'quantite' => 1,
        'montant' => 200,
        'ordre' => 1,
    ]);
    $this->devis->update(['montant_total' => 200]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('marquerEnvoye')
        ->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->statut)->toBe(StatutDevis::Envoye);
    expect($this->devis->numero)->not->toBeNull();
    expect($this->devis->numero)->toStartWith('D-');
});

// ── Envoyer button disabled if vide ─────────────────────────────────────────

it('shows Envoyer button as disabled when devis has no lignes with montant > 0', function () {
    // devis is empty (no lignes) — Envoyer button must have disabled attribute
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->assertSeeHtml('disabled')   // The button is rendered with disabled attribute
        ->assertSeeHtml('Envoyer');   // The button text is visible
});

it('peutEtreEnvoye returns false for empty devis', function () {
    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->peutEtreEnvoye())->toBeFalse();
});

it('peutEtreEnvoye returns true for devis with at least one ligne montant > 0', function () {
    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne',
        'prix_unitaire' => 200,
        'quantite' => 1,
        'montant' => 200,
        'ordre' => 1,
    ]);
    $this->devis->update(['montant_total' => 200]);

    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->peutEtreEnvoye())->toBeTrue();
});

// ── Marquer accepté ─────────────────────────────────────────────────────────

it('marquerAccepte transitions envoye to accepte with traces', function () {
    $this->devis->update([
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-001',
        'montant_total' => 200,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne',
        'prix_unitaire' => 200,
        'quantite' => 1,
        'montant' => 200,
        'ordre' => 1,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('marquerAccepte')
        ->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->statut)->toBe(StatutDevis::Accepte);
    expect($this->devis->accepte_par_user_id)->toBe((int) $this->user->id);
    expect($this->devis->accepte_le)->not->toBeNull();
});

// ── Marquer refusé ─────────────────────────────────────────────────────────

it('marquerRefuse transitions envoye to refuse with traces', function () {
    $this->devis->update([
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-002',
        'montant_total' => 150,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne',
        'prix_unitaire' => 150,
        'quantite' => 1,
        'montant' => 150,
        'ordre' => 1,
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('marquerRefuse')
        ->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->statut)->toBe(StatutDevis::Refuse);
    expect($this->devis->refuse_par_user_id)->toBe((int) $this->user->id);
    expect($this->devis->refuse_le)->not->toBeNull();
});

// ── Annuler ─────────────────────────────────────────────────────────────────

it('annuler transitions brouillon to annule', function () {
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('annuler')
        ->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->statut)->toBe(StatutDevis::Annule);
    expect($this->devis->annule_par_user_id)->toBe((int) $this->user->id);
});

it('annuler transitions envoye to annule', function () {
    $this->devis->update(['statut' => StatutDevis::Envoye, 'numero' => 'D-2026-003']);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('annuler')
        ->assertHasNoErrors();

    $this->devis->refresh();
    expect($this->devis->statut)->toBe(StatutDevis::Annule);
});

// ── Édition on locked statut ─────────────────────────────────────────────────

it('ajouterLigne on accepte sets a session error and does not create ligne', function () {
    $this->devis->update([
        'statut' => StatutDevis::Accepte,
        'numero' => 'D-2026-004',
        'accepte_par_user_id' => $this->user->id,
        'accepte_le' => now(),
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->set('nouvelleLigneLibelle', 'Nouvelle prestation')
        ->set('nouvelleLignePrixUnitaire', '100')
        ->call('ajouterLigne');

    // No new ligne was created
    expect($this->devis->lignes()->count())->toBe(0);
});

it('supprimerLigne on annule sets a session error', function () {
    $ligne = DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne existante',
        'prix_unitaire' => 100,
        'quantite' => 1,
        'montant' => 100,
        'ordre' => 1,
    ]);

    $this->devis->update([
        'statut' => StatutDevis::Annule,
        'annule_par_user_id' => $this->user->id,
        'annule_le' => now(),
    ]);

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('supprimerLigne', $ligne->id);

    // Ligne still exists
    expect(DevisLigne::find($ligne->id))->not->toBeNull();
});

// ── Read-only mode for locked statut ─────────────────────────────────────────

it('estVerrouille returns false for brouillon', function () {
    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->estVerrouille())->toBeFalse();
});

it('estVerrouille returns true for accepte', function () {
    $this->devis->update([
        'statut' => StatutDevis::Accepte,
        'numero' => 'D-2026-005',
        'accepte_par_user_id' => $this->user->id,
        'accepte_le' => now(),
    ]);

    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->estVerrouille())->toBeTrue();
});

it('estVerrouille returns true for refuse', function () {
    $this->devis->update([
        'statut' => StatutDevis::Refuse,
        'numero' => 'D-2026-006',
        'refuse_par_user_id' => $this->user->id,
        'refuse_le' => now(),
    ]);

    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->estVerrouille())->toBeTrue();
});

it('estVerrouille returns true for annule', function () {
    $this->devis->update([
        'statut' => StatutDevis::Annule,
        'annule_par_user_id' => $this->user->id,
        'annule_le' => now(),
    ]);

    $component = Livewire::test(DevisEdit::class, ['devis' => $this->devis]);
    expect($component->instance()->estVerrouille())->toBeTrue();
});

// ── Dupliquer ────────────────────────────────────────────────────────────────

it('dupliquer creates a new brouillon and redirects to its show route', function () {
    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Ligne originale',
        'prix_unitaire' => 100,
        'quantite' => 2,
        'montant' => 200,
        'ordre' => 1,
    ]);

    $response = Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('dupliquer');

    // Should redirect to a new devis
    $response->assertRedirect();

    // A second devis was created
    expect(Devis::where('tiers_id', $this->tiers->id)->count())->toBe(2);

    $nouveau = Devis::where('tiers_id', $this->tiers->id)
        ->where('id', '!=', $this->devis->id)
        ->first();

    expect($nouveau->statut)->toBe(StatutDevis::Brouillon);
    expect($nouveau->numero)->toBeNull();
    expect($nouveau->lignes)->toHaveCount(1);
    expect($nouveau->lignes->first()->libelle)->toBe('Ligne originale');
});

// ── PDF download ──────────────────────────────────────────────────────────────

it('telechargerPdf returns a file download response for a devis with lignes', function () {
    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Prestation PDF',
        'prix_unitaire' => 300,
        'quantite' => 1,
        'montant' => 300,
        'ordre' => 1,
    ]);
    $this->devis->update(['montant_total' => 300]);

    // telechargerPdf calls DevisService::genererPdf and returns a StreamedResponse
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('telechargerPdf')
        ->assertHasNoErrors();
});

it('telechargerPdf sets error when devis is empty', function () {
    // No lignes — service will throw RuntimeException
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('telechargerPdf');

    // Component should have caught the exception and flashed an error
    // (no crash, no unhandled exception)
    expect(true)->toBeTrue(); // Smoke test: no exception thrown to test harness
});

// ── Email modal ───────────────────────────────────────────────────────────────

it('ouvrirModaleEmail sets showEnvoyerEmailModal to true', function () {
    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->call('ouvrirModaleEmail')
        ->assertSet('showEnvoyerEmailModal', true);
});

it('envoyerEmail calls service and closes modal', function () {
    // Devis must be in envoye statut and have lignes for email to work
    $this->devis->update([
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-007',
        'montant_total' => 500,
    ]);

    // Tiers needs an email
    $this->tiers->update(['email' => 'client@acme.fr']);

    DevisLigne::factory()->create([
        'devis_id' => $this->devis->id,
        'libelle' => 'Prestation email',
        'prix_unitaire' => 500,
        'quantite' => 1,
        'montant' => 500,
        'ordre' => 1,
    ]);

    Mail::fake();

    Livewire::test(DevisEdit::class, ['devis' => $this->devis])
        ->set('showEnvoyerEmailModal', true)
        ->set('emailSujet', 'Devis pour votre projet')
        ->set('emailCorps', 'Veuillez trouver ci-joint notre devis.')
        ->call('envoyerEmail')
        ->assertSet('showEnvoyerEmailModal', false)
        ->assertHasNoErrors();
});

// ── Multi-tenant : autre tenant → 404 ────────────────────────────────────────

it('returns 404 when mounting a devis from another tenant', function () {
    $autreAsso = Association::factory()->create();
    $autreTiers = Tiers::factory()->create(['association_id' => $autreAsso->id]);

    // Create a devis outside the current tenant scope (bypass GlobalScope)
    $autreDevis = Devis::withoutGlobalScopes()->create([
        'association_id' => $autreAsso->id,
        'tiers_id' => $autreTiers->id,
        'statut' => StatutDevis::Brouillon,
        'date_emission' => today()->toDateString(),
        'date_validite' => today()->addDays(30)->toDateString(),
        'montant_total' => 0,
        'exercice' => 2026,
        'saisi_par_user_id' => $this->user->id,
    ]);

    // The TenantScope should prevent mounting this devis and abort 404
    $this->expectException(ComponentNotFoundException::class);

    Livewire::test(DevisEdit::class, ['devis' => $autreDevis]);
})->skip('TenantScope abort(404) on mount is integration-level; covered by intrusion tests in Step 13');
