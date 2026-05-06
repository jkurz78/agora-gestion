<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\User;
use App\Tenant\TenantContext;

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
