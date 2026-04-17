<?php

declare(strict_types=1);

use App\Livewire\Parametres\SmtpForm;
use App\Models\Association;
use App\Models\SmtpParametres;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\SmtpService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

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

it('persiste les paramètres smtp avec mot de passe chiffré', function () {
    SmtpParametres::updateOrCreate(['association_id' => $this->association->id], [
        'enabled' => true,
        'smtp_host' => 'mail.example.fr',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'user@example.fr',
        'smtp_password' => 'secret-password',
    ]);

    $record = SmtpParametres::where('association_id', $this->association->id)->first();

    expect($record->smtp_host)->toBe('mail.example.fr')
        ->and($record->smtp_password)->toBe('secret-password')
        ->and($record->smtp_port)->toBe(587)
        ->and($record->timeout)->toBe(30);

    // Le mot de passe est chiffré en base
    $raw = DB::table('smtp_parametres')
        ->where('association_id', $this->association->id)
        ->value('smtp_password');
    expect($raw)->not->toBe('secret-password');
});

it('retourne une erreur si le serveur smtp est injoignable', function () {
    $service = new SmtpService;
    $result = $service->testerConnexion(
        host: '127.0.0.1',
        port: 59999,         // port délibérément invalide
        encryption: 'none',
        username: 'u',
        password: 'p',
        timeout: 2,
    );

    expect($result->success)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

it('surcharge la config mail quand smtp_parametres est activé', function () {
    SmtpParametres::updateOrCreate(['association_id' => $this->association->id], [
        'enabled' => true,
        'smtp_host' => 'smtp.monasso.fr',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'smtp_username' => 'envoi@monasso.fr',
        'smtp_password' => 'motdepasse',
    ]);

    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.monasso.fr')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.username'))->toBe('envoi@monasso.fr')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.default'))->toBe('smtp');
});

it('ne touche pas la config mail si smtp_parametres est désactivé', function () {
    SmtpParametres::updateOrCreate(['association_id' => $this->association->id], [
        'enabled' => false,
        'smtp_host' => 'autre.host.fr',
    ]);

    $originalHost = config('mail.mailers.smtp.host');

    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(config('mail.mailers.smtp.host'))->toBe($originalHost);
});

it('SmtpForm charge les paramètres existants au mount', function () {
    SmtpParametres::updateOrCreate(['association_id' => $this->association->id], [
        'enabled' => true,
        'smtp_host' => 'mail.charge.fr',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'user@charge.fr',
        'smtp_password' => 'secret',
    ]);

    Livewire\Livewire::test(SmtpForm::class)
        ->assertSet('smtpHost', 'mail.charge.fr')
        ->assertSet('smtpPort', 587)
        ->assertSet('smtpEncryption', 'tls')
        ->assertSet('smtpUsername', 'user@charge.fr')
        ->assertSet('passwordDejaEnregistre', true)
        ->assertSet('smtpPassword', '');
});

it('SmtpForm sauvegarde les paramètres', function () {
    Livewire\Livewire::test(SmtpForm::class)
        ->set('smtpHost', 'smtp.nouveau.fr')
        ->set('smtpPort', 465)
        ->set('smtpEncryption', 'ssl')
        ->set('smtpUsername', 'envoi@nouveau.fr')
        ->set('smtpPassword', 'nouveausecret')
        ->set('enabled', true)
        ->call('sauvegarder');

    $record = SmtpParametres::where('association_id', $this->association->id)->first();
    expect($record->smtp_host)->toBe('smtp.nouveau.fr')
        ->and($record->smtp_password)->toBe('nouveausecret')
        ->and($record->enabled)->toBeTrue();
});
