<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\Adhesions\FormulesList;
use App\Livewire\Parametres\AssociationForm;
use App\Livewire\Parametres\HelloassoForm;
use App\Livewire\Parametres\HelloassoSyncConfig;
use App\Livewire\Parametres\IncomingMailForm;
use App\Livewire\Parametres\SmtpForm;
use App\Livewire\PlanComptable;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Garde d'autorisation des composants d'écran de Paramètres.
 *
 * Le middleware CheckEspaceAccess ne garde que le GET initial : Livewire ne
 * rejoue que des middlewares d'authentification sur /livewire/update. Chacun
 * des sept composants ci-dessous porte donc AutoriseEcranParametre, dont
 * booted() rejoue la matrice de ParametresNavigation à chaque requête
 * Livewire — montage inclus, ce que ->test() déclenche.
 *
 * Les couples (rôle autorisé / rôle refusé) sont choisis pour être les
 * frontières les plus signifiantes de la matrice plutôt que Consultation
 * partout : Comptable et Gestionnaire sont chacun autorisés ailleurs dans
 * les Paramètres, donc les refuser ici est la preuve la plus solide que la
 * garde lit bien la clé d'écran propre à CHAQUE composant.
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function connecterAvecRolePourGarde(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

// ── formules-adhesion (FormulesList) — admin + gestionnaire ────────────────

it('un gestionnaire monte FormulesList sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(FormulesList::class)->assertOk();
});

it('un comptable est refusé au montage de FormulesList', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(FormulesList::class)->assertForbidden();
});

// ── plan-comptable (PlanComptable) — admin + comptable ──────────────────────

it('un comptable monte PlanComptable sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(PlanComptable::class)->assertOk();
});

it('un gestionnaire est refusé au montage de PlanComptable', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(PlanComptable::class)->assertForbidden();
});

// ── informations (AssociationForm) — admin seul ─────────────────────────────

it('un admin monte AssociationForm sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(AssociationForm::class)->assertOk();
});

it('un comptable est refusé au montage de AssociationForm', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(AssociationForm::class)->assertForbidden();
});

// ── helloasso (HelloassoForm) — admin seul ──────────────────────────────────

it('un admin monte HelloassoForm sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(HelloassoForm::class)->assertOk();
});

it('un gestionnaire est refusé au montage de HelloassoForm', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(HelloassoForm::class)->assertForbidden();
});

// ── helloasso (HelloassoSyncConfig) — sous-composant, admin seul ───────────

it('un admin monte HelloassoSyncConfig sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(HelloassoSyncConfig::class)->assertOk();
});

it('un comptable est refusé au montage de HelloassoSyncConfig', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(HelloassoSyncConfig::class)->assertForbidden();
});

// ── reception-documents (IncomingMailForm) — admin seul ─────────────────────

it('un admin monte IncomingMailForm sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(IncomingMailForm::class)->assertOk();
});

it('un gestionnaire est refusé au montage de IncomingMailForm', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(IncomingMailForm::class)->assertForbidden();
});

// ── envoi-emails (SmtpForm) — admin seul ────────────────────────────────────

it('un admin monte SmtpForm sans erreur', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(SmtpForm::class)->assertOk();
});

it('un comptable est refusé au montage de SmtpForm', function (): void {
    $user = connecterAvecRolePourGarde($this->association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(SmtpForm::class)->assertForbidden();
});
