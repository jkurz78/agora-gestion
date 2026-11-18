<?php

declare(strict_types=1);

use App\Livewire\Tiers\FicheTiers;
use App\Livewire\Tiers\Onglets\Adhesion;
use App\Models\Adhesion as AdhesionModel;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
 * Onglet Adhésion de la fiche tiers : une adhésion offerte se modifie (via l'assistant
 * en mode modification) et se supprime (confirmation en modale). Une cotisation garde
 * sa seule action : modifier sa transaction.
 */

function utilisateurAvecRole(string $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), ['role' => $role, 'joined_at' => now()]);

    return $user;
}

beforeEach(function (): void {
    $this->tiers = Tiers::factory()->create();
    $this->offerte = AdhesionModel::factory()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2025, 'notes' => 'Président']);
    $this->payee = AdhesionModel::factory()->payee()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2024]);
});

it('propose modifier et supprimer sur l\'adhésion offerte seulement', function (): void {
    Livewire::actingAs(utilisateurAvecRole('gestionnaire'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->assertSeeHtml("modifier-adhesion-offerte', { adhesionId: {$this->offerte->id} }")
        ->assertSeeHtml("demanderSuppression({$this->offerte->id})")
        ->assertDontSeeHtml("adhesionId: {$this->payee->id} }")
        ->assertDontSeeHtml("demanderSuppression({$this->payee->id})");
});

it('ne propose rien à un rôle en lecture seule', function (): void {
    Livewire::actingAs(utilisateurAvecRole('consultation'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->assertDontSeeHtml('modifier-adhesion-offerte')
        ->assertDontSeeHtml('demanderSuppression(');
});

it('supprime l\'adhésion offerte après confirmation', function (): void {
    Livewire::actingAs(utilisateurAvecRole('admin'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->call('demanderSuppression', $this->offerte->id)
        ->assertSet('adhesionASupprimerId', (int) $this->offerte->id)
        ->assertSee('Supprimer cette adhésion offerte ?')
        ->call('confirmerSuppression')
        ->assertSet('adhesionASupprimerId', null)
        ->assertDontSee('Président');

    expect(AdhesionModel::find($this->offerte->id))->toBeNull()
        ->and(AdhesionModel::withTrashed()->find($this->offerte->id))->not->toBeNull();
});

it('prévient la fiche tiers après une suppression, pour son compteur d\'onglet', function (): void {
    Livewire::actingAs(utilisateurAvecRole('admin'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->call('demanderSuppression', $this->offerte->id)
        ->call('confirmerSuppression')
        ->assertDispatched('adhesion-supprimee');
});

it('la fiche tiers recompte ses adhésions quand l\'onglet en supprime une', function (): void {
    $this->actingAs(utilisateurAvecRole('admin'));
    $fiche = Livewire::test(FicheTiers::class, ['tiers' => $this->tiers]);
    expect(preg_replace('#<!--.*?-->#s', '', $fiche->html()))->toMatch('#Adhésion\s*<span class="text-muted">\(2\)</span>#u');

    expect($fiche->effects['listeners'] ?? [])->toContain('adhesion-supprimee');

    $this->offerte->delete();

    $fiche->dispatch('adhesion-supprimee');
    expect(preg_replace('#<!--.*?-->#s', '', $fiche->html()))->toMatch('#Adhésion\s*<span class="text-muted">\(1\)</span>#u');
});

it('annuler la confirmation ne supprime rien', function (): void {
    Livewire::actingAs(utilisateurAvecRole('admin'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->call('demanderSuppression', $this->offerte->id)
        ->call('annulerSuppression')
        ->assertSet('adhesionASupprimerId', null)
        ->assertDontSee('Supprimer cette adhésion offerte ?');

    expect(AdhesionModel::find($this->offerte->id))->not->toBeNull();
});

it('refuse de supprimer une cotisation, même appelée directement', function (): void {
    Livewire::actingAs(utilisateurAvecRole('admin'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->call('demanderSuppression', $this->payee->id)
        ->call('confirmerSuppression')
        ->assertSet('adhesionASupprimerId', null)
        ->assertNotDispatched('adhesion-supprimee');

    expect(AdhesionModel::find($this->payee->id))->not->toBeNull();
});

it('refuse la suppression à un rôle en lecture seule', function (): void {
    Livewire::actingAs(utilisateurAvecRole('consultation'))
        ->test(Adhesion::class, ['tiers' => $this->tiers])
        ->call('demanderSuppression', $this->offerte->id)
        ->assertForbidden();

    expect(AdhesionModel::find($this->offerte->id))->not->toBeNull();
});

it('écoute l\'assistant pour se rafraîchir après une modification', function (): void {
    // L'assistant est un autre composant : sans écouteur, le navigateur n'enverrait
    // aucune requête à l'onglet, qui garderait l'ancienne ligne à l'écran.
    $onglet = Livewire::actingAs(utilisateurAvecRole('admin'))
        ->test(Adhesion::class, ['tiers' => $this->tiers]);

    expect($onglet->effects['listeners'] ?? [])->toContain('adhesion-modifiee');

    $this->offerte->update(['notes' => 'Co-président']);

    $onglet->dispatch('adhesion-modifiee')
        ->assertSee('Co-président');
});
