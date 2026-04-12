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

it('appends opt-out link automatically when not in body', function () {
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

it('does not append opt-out footer when {lien_optout} is in body', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>Pour vous désabonner : <a href="{lien_optout}">cliquez ici</a></p>',
        trackingToken: 'custom-token',
    );

    // Should NOT have the auto-appended footer paragraph
    expect($mail->corpsHtml)->not->toContain('Se désinscrire des communications');
    // But should still have the tracking pixel
    expect($mail->corpsHtml)->toContain('custom-token');
});

it('does not append opt-out footer when {lien_desinscription} is in body', function () {
    $mail = new CommunicationTiersMail(
        prenom: 'Jean',
        nom: 'DUPONT',
        email: 'jean@example.com',
        objet: 'Test',
        corps: '<p>Infos {lien_desinscription}</p>',
        trackingToken: 'desinsc-token',
    );

    expect($mail->corpsHtml)->not->toContain('Se désinscrire des communications');
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
