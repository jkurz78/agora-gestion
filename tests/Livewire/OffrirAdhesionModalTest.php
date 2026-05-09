<?php

declare(strict_types=1);

use App\Livewire\OffrirAdhesionModal;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Services\AdhesionService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('Le modal s\'ouvre via l\'event offrir-adhesion', function (): void {
    Livewire::actingAs($this->user)
        ->test(OffrirAdhesionModal::class)
        ->assertSet('visible', false)
        ->dispatch('offrir-adhesion')
        ->assertSet('visible', true);
});

it('Validation : tiers obligatoire, exercice obligatoire, motif obligatoire min 3 max 255', function (): void {
    Livewire::actingAs($this->user)
        ->test(OffrirAdhesionModal::class)
        ->dispatch('offrir-adhesion')
        ->set('tiersId', null)
        ->set('exercice', null)
        ->set('motif', 'ab')
        ->call('submit')
        ->assertHasErrors(['tiersId' => 'required', 'exercice' => 'required', 'motif' => 'min']);

    $longMotif = str_repeat('a', 256);
    Livewire::actingAs($this->user)
        ->test(OffrirAdhesionModal::class)
        ->dispatch('offrir-adhesion')
        ->set('tiersId', null)
        ->set('exercice', null)
        ->set('motif', $longMotif)
        ->call('submit')
        ->assertHasErrors(['motif' => 'max']);
});

it('Soumission valide crée une adhésion gratuite via le service', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($this->user)
        ->test(OffrirAdhesionModal::class)
        ->dispatch('offrir-adhesion')
        ->set('tiersId', $tiers->id)
        ->set('exercice', 2025)
        ->set('motif', 'Membre d\'honneur')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('visible', false);

    $adhesion = Adhesion::where('tiers_id', $tiers->id)
        ->where('exercice', 2025)
        ->first();

    expect($adhesion)->not->toBeNull()
        ->and($adhesion->estGratuite())->toBeTrue()
        ->and($adhesion->notes)->toBe('Membre d\'honneur')
        ->and($adhesion->transaction_id)->toBeNull();
});

it('Doublon refusé : flash error si une adhésion existe déjà sur le tiers/exercice', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Adhesion::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'notes' => 'Bénévole',
    ]);

    $countBefore = Adhesion::where('tiers_id', $tiers->id)->where('exercice', 2025)->count();

    // Note : on passe par instance() pour que session()->flash() reste dans le contexte
    // du test parent (Livewire::test sous-request isole la session).
    $test = Livewire::actingAs($this->user)->test(OffrirAdhesionModal::class);
    $test->instance()->tiersId = $tiers->id;
    $test->instance()->exercice = 2025;
    $test->instance()->motif = 'Second essai';
    app(AdhesionService::class);
    $test->instance()->submit(app(AdhesionService::class));

    expect(Adhesion::where('tiers_id', $tiers->id)->where('exercice', 2025)->count())->toBe($countBefore);
    expect(session('error'))->not->toBeNull();
});

it('Dispatch event adhesion-creee après succès', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($this->user)
        ->test(OffrirAdhesionModal::class)
        ->dispatch('offrir-adhesion')
        ->set('tiersId', $tiers->id)
        ->set('exercice', 2025)
        ->set('motif', 'Membre d\'honneur')
        ->call('submit')
        ->assertDispatched('adhesion-creee')
        ->assertSet('visible', false);
});
