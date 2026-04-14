<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\SmtpParametres;
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
