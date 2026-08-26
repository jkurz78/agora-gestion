<?php

declare(strict_types=1);

// Migré vers SmtpForm (Task 9 de la découpe d'AssociationForm) : l'adresse
// d'expédition (email_from, email_from_name) a quitté l'onglet Communication
// pour rejoindre l'écran dédié « Envoi d'e-mails », aux côtés du transport
// SMTP. Voir tests/Feature/Parametres/DecoupeAssociationFormTest.php et
// tests/Livewire/Parametres/SmtpExpediteurTest.php pour la couverture TDD
// complète du composant étendu — ce fichier est conservé pour ne pas perdre
// son historique de régression, adapté à sa nouvelle destination.

use App\Livewire\Parametres\SmtpForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('displays email_from fields', function () {
    Livewire::test(SmtpForm::class)
        ->assertSeeHtml('Expéditeur des e-mails')
        ->assertSeeHtml('placeholder="Nom expéditeur"')
        ->assertSeeHtml('placeholder="noreply@monasso.fr"');
});

it('saves email_from and email_from_name', function () {
    Livewire::test(SmtpForm::class)
        ->set('email_from', 'noreply@asso.fr')
        ->set('email_from_name', 'Mon Association')
        ->call('sauvegarder');

    $assoc = $this->association->fresh();
    expect($assoc->email_from)->toBe('noreply@asso.fr')
        ->and($assoc->email_from_name)->toBe('Mon Association');
});

it('validates email_from is a valid email', function () {
    Livewire::test(SmtpForm::class)
        ->set('email_from', 'not-an-email')
        ->call('sauvegarder')
        ->assertHasErrors(['email_from']);
});

it('loads existing email_from on mount', function () {
    $this->association->update(['email_from' => 'existing@asso.fr', 'email_from_name' => 'Existing']);

    Livewire::test(SmtpForm::class)
        ->assertSet('email_from', 'existing@asso.fr')
        ->assertSet('email_from_name', 'Existing');
});
