<?php

declare(strict_types=1);

use App\Enums\Newsletter\DesinscriptionAction;
use App\Enums\RoleAssociation;
use App\Livewire\Newsletter\CreateTiersModal;
use App\Livewire\Newsletter\InscriptionsList;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Models\Transaction;
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

it('openMergeModal dispatch open-tiers-merge avec context newsletter_import', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $bob = Tiers::factory()->create(['prenom' => 'Bob', 'nom' => 'MARTIN', 'email' => 'bob@x.fr']);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'bob@x.fr']);

    Livewire::test(InscriptionsList::class)
        ->call('openMergeModal', $req->id, $bob->id)
        ->assertDispatched('open-tiers-merge', context: 'newsletter_import');
});

it('handler tiers-merge-confirmed lie le buffer au Tiers', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $bob = Tiers::factory()->create();
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    Livewire::test(InscriptionsList::class)
        ->call('onMergeConfirmed', $bob->id, 'newsletter_import', ['subscription_request_id' => $req->id]);

    $req->refresh();
    expect((int) $req->tiers_id)->toBe((int) $bob->id);
});

it('handler tiers-merge-confirmed ignore les autres contexts', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $bob = Tiers::factory()->create();
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    Livewire::test(InscriptionsList::class)
        ->call('onMergeConfirmed', $bob->id, 'csv_import', ['merge_data' => []]);

    $req->refresh();
    expect($req->tiers_id)->toBeNull();
});

it('onglet desinscriptions liste les unsubscribed avec tiers_id non traités', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $tiers1 = Tiers::factory()->create(['email' => 'todo@x.fr']);
    $tiers2 = Tiers::factory()->create(['email' => 'done@x.fr']);

    SubscriptionRequest::factory()->desinscriptionAtraiter($tiers1->id)->create(['email' => 'todo@x.fr']);
    SubscriptionRequest::factory()->desinscriptionTraitee($tiers2->id)->create(['email' => 'done@x.fr']);

    Livewire::test(InscriptionsList::class)
        ->call('setTab', 'desinscriptions')
        ->assertSee('todo@x.fr')
        ->assertDontSee('done@x.fr');
});

it('action applyOptout positionne email_optout sur le Tiers', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $tiers = Tiers::factory()->create(['email_optout' => false]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    Livewire::test(InscriptionsList::class)
        ->call('setTab', 'desinscriptions')
        ->call('applyOptout', $req->id);

    $tiers->refresh();
    $req->refresh();
    expect($tiers->email_optout)->toBeTrue();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Optout);
});

it('action applyDelete supprime un Tiers sans dépendance', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $tiers = Tiers::factory()->create();
    $tiersId = $tiers->id;
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiersId)->create();

    Livewire::test(InscriptionsList::class)
        ->call('setTab', 'desinscriptions')
        ->call('applyDelete', $req->id);

    expect(Tiers::find($tiersId))->toBeNull();
    $req->refresh();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Deleted);
});

it('action applyDelete affiche une erreur si Tiers a des dépendances', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $tiers = Tiers::factory()->create();
    Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    Livewire::test(InscriptionsList::class)
        ->call('setTab', 'desinscriptions')
        ->call('applyDelete', $req->id)
        ->assertHasErrors('delete');

    expect(Tiers::find($tiers->id))->not->toBeNull();
    $req->refresh();
    expect($req->desinscription_traitee_at)->toBeNull();
});

it('action applyNoop marque traitée sans muter le Tiers', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);
    $tiers = Tiers::factory()->create(['email_optout' => false]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    Livewire::test(InscriptionsList::class)
        ->call('setTab', 'desinscriptions')
        ->call('applyNoop', $req->id);

    $tiers->refresh();
    $req->refresh();
    expect($tiers->email_optout)->toBeFalse();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Noop);
});

it('topbar n affiche pas l entrée newsletter si compteur vaut 0', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);

    $response = $this->get('/dashboard');

    $response->assertDontSee('Inscriptions newsletter');
});

it('topbar affiche l entrée newsletter avec le compteur cumul', function () {
    newsletterInboxLogin(RoleAssociation::Admin->value);

    SubscriptionRequest::factory()->inscriptionAtraiter()->count(2)->create();
    $tiers = Tiers::factory()->create();
    SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    $response = $this->get('/dashboard');

    $response->assertSee('Inscriptions newsletter');
    $response->assertSeeText('3'); // 2 + 1
});

it('topbar n affiche pas l entrée newsletter pour un Consultation', function () {
    newsletterInboxLogin(RoleAssociation::Consultation->value);

    SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    $response = $this->get('/dashboard');

    $response->assertDontSee('Inscriptions newsletter');
});

it('isolation tenant : ne voit pas les buffers d une autre asso', function () {
    // Asso A booted by default via beforeEach
    $assoA = Association::find(TenantContext::currentId());
    $assoB = Association::factory()->create();

    // Crée une ligne sur asso B en bootant temporairement
    TenantContext::boot($assoB);
    SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'on-asso-b@x.fr']);

    // Reboot asso A
    TenantContext::boot($assoA);

    // L'admin connecté à l'asso A ne doit pas voir la ligne de l'asso B
    newsletterInboxLogin(RoleAssociation::Admin->value);

    Livewire::test(InscriptionsList::class)
        ->assertDontSee('on-asso-b@x.fr');
});
