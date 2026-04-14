<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\SmtpParametres;
use App\Services\SmtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test'])->save();
    }
});

it('persiste les paramètres smtp avec mot de passe chiffré', function () {
    SmtpParametres::updateOrCreate(['association_id' => 1], [
        'enabled'          => true,
        'smtp_host'        => 'mail.example.fr',
        'smtp_port'        => 587,
        'smtp_encryption'  => 'tls',
        'smtp_username'    => 'user@example.fr',
        'smtp_password'    => 'secret-password',
    ]);

    $record = SmtpParametres::where('association_id', 1)->first();

    expect($record->smtp_host)->toBe('mail.example.fr')
        ->and($record->smtp_password)->toBe('secret-password')
        ->and($record->smtp_port)->toBe(587)
        ->and($record->timeout)->toBe(30);

    // Le mot de passe est chiffré en base
    $raw = \Illuminate\Support\Facades\DB::table('smtp_parametres')
        ->where('association_id', 1)
        ->value('smtp_password');
    expect($raw)->not->toBe('secret-password');
});

it('retourne une erreur si le serveur smtp est injoignable', function () {
    $service = new SmtpService();
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
    \App\Models\SmtpParametres::updateOrCreate(['association_id' => 1], [
        'enabled'         => true,
        'smtp_host'       => 'smtp.monasso.fr',
        'smtp_port'       => 465,
        'smtp_encryption' => 'ssl',
        'smtp_username'   => 'envoi@monasso.fr',
        'smtp_password'   => 'motdepasse',
    ]);

    $provider = new \App\Providers\AppServiceProvider(app());
    $provider->boot();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.monasso.fr')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.username'))->toBe('envoi@monasso.fr')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.default'))->toBe('smtp');
});

it('ne touche pas la config mail si smtp_parametres est désactivé', function () {
    \App\Models\SmtpParametres::updateOrCreate(['association_id' => 1], [
        'enabled'    => false,
        'smtp_host'  => 'autre.host.fr',
    ]);

    $originalHost = config('mail.mailers.smtp.host');

    $provider = new \App\Providers\AppServiceProvider(app());
    $provider->boot();

    expect(config('mail.mailers.smtp.host'))->toBe($originalHost);
});
