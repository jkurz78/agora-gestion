<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\SmtpForm;
use App\Mail\TestEmail;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Quatrième des cinq déplacements qui vident AssociationForm (cf.
 * tests/Feature/Parametres/DecoupeAssociationFormTest.php) : l'onglet
 * Communication (email_from, email_from_name) quitte AssociationForm pour
 * rejoindre SmtpForm — l'écran « Envoi d'e-mails » — de sorte qu'un seul
 * endroit réponde à « comment partent les e-mails », transport SMTP et
 * expéditeur ensemble.
 *
 * Piège de la migration : sendTestEmail() construisait le TestEmail avec
 * `$this->nom`, une propriété d'AssociationForm (le nom de l'association).
 * Une fois la méthode déplacée dans SmtpForm, cette propriété n'existe plus
 * — sans re-résolution, on obtient soit une erreur, soit pire, un email de
 * test envoyé avec un nom vide sans qu'aucune erreur ne le signale. La
 * dépendance est re-résolue via CurrentAssociation::get()->nom.
 */
function connecterAvecRolePourSmtp(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('SmtpForm charge le nom et l\'adresse d\'expédition de l\'association au montage', function (): void {
    $association = Association::factory()->create([
        'email_from' => 'contact@monasso.fr',
        'email_from_name' => 'Mon Association',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourSmtp($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(SmtpForm::class)
        ->assertSet('email_from', 'contact@monasso.fr')
        ->assertSet('email_from_name', 'Mon Association');
});

it('SmtpForm enregistre l\'adresse d\'expédition sur le modèle Association', function (): void {
    $association = Association::factory()->create([
        'email_from' => null,
        'email_from_name' => null,
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourSmtp($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(SmtpForm::class)
        ->set('email_from', 'noreply@monasso.fr')
        ->set('email_from_name', 'Mon Asso')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->email_from)->toBe('noreply@monasso.fr')
        ->and($association->email_from_name)->toBe('Mon Asso');
});

it('l\'email de test porte le nom de l\'association et non une chaîne vide', function (): void {
    Mail::fake();

    $association = Association::factory()->create([
        'nom' => 'Soigner Vivre Sourire',
        'email_from' => 'contact@svs.fr',
        'email_from_name' => 'SVS',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourSmtp($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(SmtpForm::class)
        ->set('testEmailTo', 'destinataire@example.fr')
        ->call('sendTestEmail')
        ->assertHasNoErrors();

    Mail::assertSent(TestEmail::class, function (TestEmail $mail) {
        return $mail->typeNom === 'Soigner Vivre Sourire';
    });
});

it('testEmailTo n\'est jamais persisté', function (): void {
    expect(property_exists(Association::class, 'testEmailTo'))->toBeFalse();

    $association = Association::factory()->create([
        'email_from' => 'contact@monasso.fr',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourSmtp($association, RoleAssociation::Admin);

    Mail::fake();

    Livewire::actingAs($user)->test(SmtpForm::class)
        ->set('testEmailTo', 'quelqu-un@example.fr')
        ->call('sendTestEmail')
        ->assertHasNoErrors();

    // Aucune colonne testEmailTo/test_email_to n'existe sur l'association : la
    // persistance n'a pu se produire nulle part — on le confirme en s'assurant
    // qu'un nouveau montage du composant repart d'un champ vide.
    Livewire::actingAs($user)->test(SmtpForm::class)
        ->assertSet('testEmailTo', '');
});

it('un comptable reçoit 403 au montage de SmtpForm', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourSmtp($association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(SmtpForm::class)->assertForbidden();
});
