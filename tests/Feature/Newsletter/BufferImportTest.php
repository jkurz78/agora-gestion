<?php

declare(strict_types=1);

use App\Enums\Newsletter\DesinscriptionAction;
use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Enums\RoleAssociation;
use App\Models\AssociationUser;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Newsletter\BufferImportService;
use App\Services\Newsletter\Exceptions\TiersHasDependenciesException;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;

it('migration adds the 4 admin processing columns to newsletter buffer', function () {
    expect(Schema::hasColumn('newsletter_subscription_requests', 'ignored_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_traitee_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_action'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'processed_by_user_id'))->toBeTrue();
});

it('scope inscriptionsAtraiter ne renvoie que les confirmed sans tiers_id ni ignored_at', function () {
    $tiers = Tiers::factory()->create();

    SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'pending@x.fr']);
    SubscriptionRequest::factory()->importee($tiers->id)->create(['email' => 'imported@x.fr']);
    SubscriptionRequest::factory()->ignoree()->create(['email' => 'ignored@x.fr']);

    $emails = SubscriptionRequest::query()
        ->inscriptionsAtraiter()
        ->pluck('email')
        ->all();

    expect($emails)->toBe(['pending@x.fr']);
});

it('scope desinscriptionsAtraiter ne renvoie que les unsubscribed avec tiers_id et sans desinscription_traitee_at', function () {
    $tiers1 = Tiers::factory()->create();
    $tiers2 = Tiers::factory()->create();

    SubscriptionRequest::factory()->desinscriptionAtraiter($tiers1->id)->create(['email' => 'todo@x.fr']);
    SubscriptionRequest::factory()->desinscriptionTraitee($tiers2->id)->create(['email' => 'done@x.fr']);
    SubscriptionRequest::factory()->create([
        'status' => SubscriptionRequestStatus::Unsubscribed,
        'tiers_id' => null,
        'email' => 'orphan@x.fr',
    ]);

    $emails = SubscriptionRequest::query()
        ->desinscriptionsAtraiter()
        ->pluck('email')
        ->all();

    expect($emails)->toBe(['todo@x.fr']);
});

it('suggestMatch trouve un Tiers par email exact', function () {
    $bob = Tiers::factory()->create(['email' => 'bob@x.fr', 'prenom' => 'Bob', 'nom' => 'MARTIN']);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'bob@x.fr', 'prenom' => 'Robert', 'nom' => 'autre']);

    $match = app(BufferImportService::class)->suggestMatch($req);

    expect($match?->id)->toBe($bob->id);
});

it('suggestMatch fallback sur (prenom, nom) si pas de match email', function () {
    $alice = Tiers::factory()->create(['email' => 'autre@x.fr', 'prenom' => 'Alice', 'nom' => 'DUPONT']);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'inconnu@x.fr', 'prenom' => 'alice', 'nom' => 'dupont']);

    $match = app(BufferImportService::class)->suggestMatch($req);

    expect($match?->id)->toBe($alice->id);
});

it('suggestMatch renvoie null si aucun match', function () {
    Tiers::factory()->create(['email' => 'autre@x.fr', 'prenom' => 'Zoe', 'nom' => 'INCONNUE']);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create([
        'email' => 'nouveau@x.fr',
        'prenom' => 'Nouveau',
        'nom' => 'NOUVEAU',
    ]);

    $match = app(BufferImportService::class)->suggestMatch($req);

    expect($match)->toBeNull();
});

it('createTiersFromBuffer crée le Tiers et lie le buffer', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create([
        'email' => 'alice@nouveau.fr',
        'prenom' => 'Alice',
        'nom' => 'Dupont',
    ]);

    $tiers = app(BufferImportService::class)->createTiersFromBuffer($req, [
        'type' => 'particulier',
        'prenom' => 'Alice',
        'nom' => 'Dupont',
        'email' => 'alice@nouveau.fr',
        'pour_recettes' => true,
    ]);

    $req->refresh();
    expect($tiers->id)->toBeGreaterThan(0);
    expect((int) $req->tiers_id)->toBe((int) $tiers->id);
    expect((int) $req->processed_by_user_id)->toBe((int) $user->id);
    expect($req->ignored_at)->toBeNull();
});

it('linkBufferToExistingTiers lie le buffer sans modifier le Tiers', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $bob = Tiers::factory()->create(['email' => 'bob@connu.fr', 'prenom' => 'Bob', 'nom' => 'MARTIN']);
    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'bob@connu.fr']);

    app(BufferImportService::class)->linkBufferToExistingTiers($req, $bob);

    $req->refresh();
    $bob->refresh();
    expect((int) $req->tiers_id)->toBe((int) $bob->id);
    expect((int) $req->processed_by_user_id)->toBe((int) $user->id);
    expect($bob->email)->toBe('bob@connu.fr'); // Tiers inchangé
});

it('ignore marque ignored_at sans toucher tiers_id', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $req = SubscriptionRequest::factory()->inscriptionAtraiter()->create();

    app(BufferImportService::class)->ignore($req);

    $req->refresh();
    expect($req->ignored_at)->not->toBeNull();
    expect($req->tiers_id)->toBeNull();
    expect((int) $req->processed_by_user_id)->toBe((int) $user->id);
});

it('applyUnsubscribeOptout pose email_optout=true et marque desinscription_traitee_at', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create(['email_optout' => false]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    app(BufferImportService::class)->applyUnsubscribeOptout($req);

    $tiers->refresh();
    $req->refresh();
    expect($tiers->email_optout)->toBeTrue();
    expect($req->desinscription_traitee_at)->not->toBeNull();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Optout);
    expect((int) $req->processed_by_user_id)->toBe((int) $user->id);
});

it('applyUnsubscribeDelete supprime le Tiers sans dépendances', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();
    $tiersId = $tiers->id;
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiersId)->create();

    app(BufferImportService::class)->applyUnsubscribeDelete($req);

    $req->refresh();
    expect(Tiers::find($tiersId))->toBeNull();
    expect($req->desinscription_traitee_at)->not->toBeNull();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Deleted);
    expect($req->tiers_id)->toBeNull(); // cascade nullOnDelete
});

it('applyUnsubscribeDelete refuse si Tiers a des dépendances', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();
    Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    expect(fn () => app(BufferImportService::class)->applyUnsubscribeDelete($req))
        ->toThrow(TiersHasDependenciesException::class);

    expect(Tiers::find($tiers->id))->not->toBeNull();
    $req->refresh();
    expect($req->desinscription_traitee_at)->toBeNull();
});

it('applyUnsubscribeNoop marque traitée sans rien modifier sur le Tiers', function () {
    $user = User::factory()->create();
    AssociationUser::create([
        'user_id' => $user->id,
        'association_id' => TenantContext::currentId(),
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create(['email_optout' => false]);
    $req = SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create();

    app(BufferImportService::class)->applyUnsubscribeNoop($req);

    $tiers->refresh();
    $req->refresh();
    expect($tiers->email_optout)->toBeFalse();
    expect($req->desinscription_action)->toBe(DesinscriptionAction::Noop);
    expect($req->desinscription_traitee_at)->not->toBeNull();
});
