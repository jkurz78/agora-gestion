<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\Parametres\ParametresNavigation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function connecterAvecRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('applique la matrice rôle × écran sur la ROUTE, pas sur l’affichage', function (): void {
    $roles = [
        RoleAssociation::Admin,
        RoleAssociation::Comptable,
        RoleAssociation::Gestionnaire,
        RoleAssociation::Consultation,
    ];

    $ecransVerifies = 0;

    foreach ($roles as $role) {
        $user = connecterAvecRole($this->association, $role);

        foreach (ParametresNavigation::sections() as $section) {
            foreach ($section->ecrans as $ecran) {
                // Les douze écrans ont désormais leur route (ocr-ia, dernier
                // arrivé en Task 8) : cette garde ne saute plus rien, mais on
                // la conserve pour ne pas casser le test si un écran futur
                // était déclaré en taxonomie avant sa route.
                if (! Route::has($ecran->route)) {
                    continue;
                }

                $reponse = $this->actingAs($user)->get(route($ecran->route));
                $autorise = $ecran->accessiblePar($role);
                $ecransVerifies++;

                expect($reponse->status())->toBe(
                    $autorise ? 200 : 403,
                    "{$role->value} sur {$ecran->cle} — attendu ".($autorise ? '200' : '403'),
                );
            }
        }
    }

    // Sans cette garde, le test passerait en ne vérifiant RIEN le jour où les
    // noms de route changeraient : 4 rôles × 12 écrans routés = 48 contrôles.
    expect($ecransVerifies)->toBeGreaterThanOrEqual(48);
});

it('aucun écran porteur d’un secret n’est atteignable par un non-admin', function (): void {
    // Contrôle SÉPARÉ de la matrice : un échec ici signale une fuite de
    // justificatif d'accès (clé API, mot de passe SMTP), pas une erreur d'ergonomie.
    $porteursDeSecret = ['helloasso', 'reception-documents', 'envoi-emails', 'ocr-ia'];
    $controles = 0;

    foreach ([RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation] as $role) {
        $user = connecterAvecRole($this->association, $role);

        foreach (ParametresNavigation::sections() as $section) {
            foreach ($section->ecrans as $ecran) {
                if (! in_array($ecran->cle, $porteursDeSecret, true)) {
                    continue;
                }
                if (! Route::has($ecran->route)) {
                    continue;
                }

                $this->actingAs($user)
                    ->get(route($ecran->route))
                    ->assertStatus(403);
                $controles++;
            }
        }
    }

    // 3 rôles × 4 écrans à secret, tous routés depuis ocr-ia (Task 8).
    expect($controles)->toBeGreaterThanOrEqual(12);
});
