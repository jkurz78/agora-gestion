<?php

declare(strict_types=1);

use App\Helpers\EmailLogo;
use App\Mail\AttestationPresenceMail;
use App\Mail\CommunicationTiersMail;
use App\Mail\MessageLibreMail;
use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ── EmailLogo::resolve() ─────────────────────────────────────────────────────

it('EmailLogo::resolve() returns null when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);

    expect(EmailLogo::resolve($association))->toBeNull();
});

it('EmailLogo::resolve() returns null when logo file does not exist on disk', function () {
    $association = Association::factory()->create(['logo_path' => 'missing.png']);
    // File not stored on disk

    expect(EmailLogo::resolve($association))->toBeNull();
});

it('EmailLogo::resolve() returns path and mime when logo exists on disk', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');

    $result = EmailLogo::resolve($association);

    expect($result)->not->toBeNull()
        ->and($result)->toHaveKey('path')
        ->and($result)->toHaveKey('mime')
        ->and($result['path'])->toContain("associations/{$id}/branding/logo.png");
});

// ── {logo} variable in corps → cid: reference ────────────────────────────────

it('{logo} variable resolves to cid:logo-asso img tag when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $vars = EmailLogo::variables();

    expect($vars['{logo}'])->toContain('cid:logo-asso');
});

it('{logo} variable resolves to empty string when no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $vars = EmailLogo::variables();

    expect($vars['{logo}'])->toBe('');
});

// ── AttestationPresenceMail ───────────────────────────────────────────────────

it('AttestationPresenceMail: corps contains cid:logo-asso when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>{logo}Bonjour {prenom}</p>',
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('AttestationPresenceMail: has 2 attachments (pdf + logo inline) when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: null,
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    $attachments = $mail->attachments();

    // PDF + logo inline
    expect($attachments)->toHaveCount(2);

    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );
    expect($logoAttachment)->not->toBeNull();
});

it('AttestationPresenceMail: has 1 attachment (pdf only) when no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: null,
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->as)->toBe('attestation.pdf');
});

it('AttestationPresenceMail: corps does not contain cid:logo-asso when no logo and template has no {logo}', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: null,
        dateSeance: null,
        customObjet: null,
        customCorps: '<p>Bonjour {prenom}</p>',
        pdfContent: '%PDF-fake',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->corpsHtml)->not->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

// ── MessageLibreMail ──────────────────────────────────────────────────────────

it('MessageLibreMail: corps contains cid:logo-asso when logo exists and {logo} in template', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>{logo}Bonjour {prenom}</p>',
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('MessageLibreMail: logo attachment added when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>{logo}Bonjour</p>',
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
});

it('MessageLibreMail: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Jean',
        nomParticipant: 'Dupont',
        emailParticipant: 'jean@test.fr',
        operationNom: 'Formation',
        typeOperationNom: 'PSA',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: null,
        datePrecedenteSeance: null,
        numeroProchainSeance: null,
        numeroPrecedenteSeance: null,
        titreProchainSeance: null,
        titrePrecedenteSeance: null,
        joursAvantProchaineSeance: null,
        nbSeancesEffectuees: 5,
        nbSeancesRestantes: 5,
        objet: 'Test',
        corps: '<p>Bonjour</p>',
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});

// ── CommunicationTiersMail ────────────────────────────────────────────────────

it('CommunicationTiersMail: corps contains cid:logo-asso when logo exists and {logo} in template', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>{logo}Bonjour {prenom}</p>',
        trackingToken: null,
    );

    expect($mail->corpsHtml)->toContain('cid:logo-asso');
    expect($mail->corpsHtml)->not->toContain('base64');
});

it('CommunicationTiersMail: logo attachment added when logo exists', function () {
    $association = Association::factory()->create(['logo_path' => 'logo.png']);
    $id = $association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'fake-png-data');
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>{logo}Bonjour</p>',
        trackingToken: null,
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->not->toBeNull();
});

it('CommunicationTiersMail: no logo attachment when association has no logo', function () {
    $association = Association::factory()->create(['logo_path' => null]);
    TenantContext::boot($association);

    $mail = new CommunicationTiersMail(
        prenom: 'Alice',
        nom: 'Martin',
        email: 'alice@test.fr',
        objet: 'Test',
        corps: '<p>Bonjour</p>',
        trackingToken: null,
    );

    $attachments = $mail->attachments();
    $logoAttachment = collect($attachments)->first(
        fn (Attachment $a) => $a->as === 'logo-asso'
    );

    expect($logoAttachment)->toBeNull();
});
