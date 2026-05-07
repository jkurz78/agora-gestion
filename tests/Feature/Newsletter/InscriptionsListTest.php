<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Newsletter\CreateTiersModal;
use App\Livewire\Newsletter\InscriptionsList;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function newsletterInboxLogin(string $role): User
{
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => $association->id,
        'role' => $role,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);
    test()->actingAs($user);

    return $user;
}

it('route /newsletter/inscriptions accessible aux Admin', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);

    $response = $this->get('/newsletter/inscriptions');

    $response->assertOk();
});

it('route /newsletter/inscriptions accessible aux Comptable', function () {
    newsletterInboxLogin(RoleAssociation::Comptable->value);

    $response = $this->get('/newsletter/inscriptions');

    $response->assertOk();
});

it('route /newsletter/inscriptions refuse les Consultation', function () {
    newsletterInboxLogin(RoleAssociation::Consultation->value);

    $response = $this->get('/newsletter/inscriptions');

    $response->assertForbidden();
});

it('page affiche 2 onglets', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);

    $response = $this->get('/newsletter/inscriptions');

    $response->assertSeeText('Inscriptions à traiter');
    $response->assertSeeText('Désinscriptions à traiter');
});

it('onglet inscriptions liste les confirmed sans tiers_id ni ignored_at', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);

    SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'pending@x.fr']);
    $tiers = Tiers::factory()->create();
    SubscriptionRequest::factory()->importee($tiers->id)->create(['email' => 'imported@x.fr']);
    SubscriptionRequest::factory()->ignoree()->create(['email' => 'ignored@x.fr']);

    Livewire::test(InscriptionsList::class)
        ->assertSee('pending@x.fr')
        ->assertDontSee('imported@x.fr')
        ->assertDontSee('ignored@x.fr');
});

it('onglet inscriptions affiche le bouton Créer si aucun match', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    SubscriptionRequest::factory()->inscriptionAtraiter()->create([
        'email' => 'nouveau@x.fr',
        'prenom' => 'Nouveau',
        'nom' => 'NOUVEAU',
    ]);

    Livewire::test(InscriptionsList::class)
        ->assertSee('Créer le tiers');
});

it('onglet inscriptions affiche le bouton Fusionner si match email', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    Tiers::factory()->create(['email' => 'bob@x.fr', 'prenom' => 'Bob', 'nom' => 'MARTIN']);
    SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'bob@x.fr']);

    Livewire::test(InscriptionsList::class)
        ->assertSee('Fusionner avec Bob MARTIN');
});

it('action ignore retire la ligne de la liste', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'spam@x.fr']);

    Livewire::test(InscriptionsList::class)
        ->call('ignore', $req->id)
        ->assertDontSee('spam@x.fr');

    $req->refresh();
    expect($req->ignored_at)->not->toBeNull();
});

it('openCreateModal dispatch un évènement vers CreateTiersModal', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    Livewire::test(InscriptionsList::class)
        ->call('openCreateModal', $req->id)
        ->assertDispatched('open-newsletter-create-tiers', requestId: $req->id);
});

it('CreateTiersModal pré-remplit depuis le buffer et crée le Tiers', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create([
        'email' => 'alice@nouveau.fr',
        'prenom' => 'Alice',
        'nom' => 'Dupont',
    ]);

    Livewire::test(CreateTiersModal::class)
        ->call('open', $req->id)
        ->assertSet('email', 'alice@nouveau.fr')
        ->assertSet('prenom', 'Alice')
        ->assertSet('nom', 'Dupont')
        ->assertSet('type', 'particulier')
        ->assertSet('pour_recettes', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $req->refresh();
    expect($req->tiers_id)->not->toBeNull();
    $tiers = Tiers::find($req->tiers_id);
    expect($tiers->email)->toBe('alice@nouveau.fr');
    expect($tiers->nom)->toBe('DUPONT'); // accesseur uppercase
});

it('CreateTiersModal valide email obligatoire', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    Livewire::test(CreateTiersModal::class)
        ->call('open', $req->id)
        ->set('email', '')
        ->call('save')
        ->assertHasErrors(['email']);
});
