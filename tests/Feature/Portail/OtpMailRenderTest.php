<?php

declare(strict_types=1);

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create(['nom' => 'Les Amis du Quartier']);
    TenantContext::boot($this->asso);
});

it('a le bon sujet avec le nom de l\'association', function () {
    $mail = new OtpMail($this->asso, '12345678');

    expect($mail->envelope()->subject)
        ->toBe('Votre code de connexion — Les Amis du Quartier');
});

it('le body contient le code à 8 chiffres', function () {
    $html = (new OtpMail($this->asso, '12345678'))->render();

    expect(str_contains($html, '12345678'))->toBeTrue();
});

it('le body contient le nom de l\'association', function () {
    $html = (new OtpMail($this->asso, '12345678'))->render();

    expect(str_contains($html, 'Les Amis du Quartier'))->toBeTrue();
});

it('le body mentionne la durée de validité de 10 minutes', function () {
    $html = (new OtpMail($this->asso, '12345678'))->render();

    expect(
        str_contains($html, '10 minutes') || str_contains($html, '10 min')
    )->toBeTrue();
});

it('le body contient le message de sécurité "ne partagez pas"', function () {
    $html = (new OtpMail($this->asso, '12345678'))->render();

    expect(
        str_contains(strtolower($html), 'ne partagez pas')
    )->toBeTrue();
});

it('le body ne contient aucun lien cliquable vers le portail', function () {
    $html = (new OtpMail($this->asso, '12345678'))->render();

    expect(str_contains($html, '/portail/'))->toBeFalse();
});
