<?php

declare(strict_types=1);

use App\Mail\CommunicationTiersMail;
use App\Models\Association;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Mon Asso'])->save();
});

it('substitutes tiers variables in body', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Bonjour {prenom}',
        corps: '<p>Cher {prenom} {nom}, votre email est {email}. Association : {association}</p>',
        trackingToken: 'abc123',
    );

    expect($mail->corpsHtml)
        ->toContain('Cher Jean DUPONT')
        ->toContain('jean@example.com')
        ->toContain('Mon Asso');
});

it('substitutes variables in subject', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Bienvenue {prenom} {nom}',
        corps: '<p>Test</p>',
        trackingToken: 'abc123',
    );

    $envelope = $mail->envelope();
    expect($envelope->subject)->toBe('Bienvenue Jean DUPONT');
});

it('appends tracking pixel when token provided', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>Test</p>',
        trackingToken: 'tok123',
    );

    expect($mail->corpsHtml)->toContain('tok123');
});

it('appends opt-out link automatically', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>Test</p>',
        trackingToken: 'optout-token',
    );

    expect($mail->corpsHtml)
        ->toContain('désinscrire')
        ->toContain('optout-token');
});

it('strips disallowed HTML tags', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>OK</p><script>alert("xss")</script>',
        trackingToken: 'abc',
    );

    expect($mail->corpsHtml)
        ->toContain('<p>OK</p>')
        ->not->toContain('<script>');
});

it('handles file attachments', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>Test</p>',
        trackingToken: 'abc',
        attachmentPaths: [],
    );

    expect($mail->attachments())->toBeEmpty();
});
