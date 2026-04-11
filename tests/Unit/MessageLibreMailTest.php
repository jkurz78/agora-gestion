<?php

declare(strict_types=1);

use App\Mail\MessageLibreMail;

/**
 * Helper: build a MessageLibreMail with sensible defaults, allowing overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeMessageLibreMail(array $overrides = []): MessageLibreMail
{
    $defaults = [
        'prenomParticipant' => 'Alice',
        'nomParticipant' => 'Durand',
        'operationNom' => 'Formation Laravel',
        'typeOperationNom' => 'Formation',
        'dateDebut' => '01/09/2025',
        'dateFin' => '30/06/2026',
        'nbSeances' => 10,
        'dateProchainSeance' => '15/04/2026',
        'datePrecedenteSeance' => '01/04/2026',
        'numeroProchainSeance' => 5,
        'numeroPrecedenteSeance' => 4,
        'objet' => 'Bonjour {prenom}',
        'corps' => '<p>Bonjour {prenom} {nom}, opération : {operation}.</p>',
        'attachmentPaths' => [],
        'typeOperationId' => null,
    ];

    $params = array_merge($defaults, $overrides);

    return new MessageLibreMail(
        prenomParticipant: $params['prenomParticipant'],
        nomParticipant: $params['nomParticipant'],
        operationNom: $params['operationNom'],
        typeOperationNom: $params['typeOperationNom'],
        dateDebut: $params['dateDebut'],
        dateFin: $params['dateFin'],
        nbSeances: $params['nbSeances'],
        dateProchainSeance: $params['dateProchainSeance'],
        datePrecedenteSeance: $params['datePrecedenteSeance'],
        numeroProchainSeance: $params['numeroProchainSeance'],
        numeroPrecedenteSeance: $params['numeroPrecedenteSeance'],
        objet: $params['objet'],
        corps: $params['corps'],
        attachmentPaths: $params['attachmentPaths'],
        typeOperationId: $params['typeOperationId'],
    );
}

it('substitutes variables in corps and stores result in corpsHtml', function () {
    $mail = makeMessageLibreMail([
        'corps' => '<p>Bonjour {prenom} {nom}, opération : {operation}, type : {type_operation}.</p>',
    ]);

    expect($mail->corpsHtml)
        ->toContain('Alice')
        ->toContain('Durand')
        ->toContain('Formation Laravel')
        ->toContain('Formation')
        ->not->toContain('{prenom}')
        ->not->toContain('{nom}')
        ->not->toContain('{operation}')
        ->not->toContain('{type_operation}');
});

it('substitutes variables in subject via envelope()', function () {
    $mail = makeMessageLibreMail([
        'prenomParticipant' => 'Bob',
        'objet' => 'Message pour {prenom} {nom}',
        'nomParticipant' => 'Martin',
    ]);

    $subject = $mail->envelope()->subject;

    expect($subject)
        ->toContain('Bob')
        ->toContain('Martin')
        ->not->toContain('{prenom}')
        ->not->toContain('{nom}');
});

it('reports unresolved variables when dateProchainSeance is null', function () {
    $corps = '<p>Prochaine séance : {date_prochaine_seance}</p>';

    $variables = [
        '{date_prochaine_seance}' => '',
        '{date_precedente_seance}' => '01/04/2026',
    ];

    $unresolved = MessageLibreMail::unresolvedVariables($corps, $variables);

    expect($unresolved)->toBe(['{date_prochaine_seance}']);
});

it('returns correct attachment count when paths are provided', function () {
    // Use real temp files so Attachment::fromPath does not fail
    $path1 = tempnam(sys_get_temp_dir(), 'mail_test_');
    $path2 = tempnam(sys_get_temp_dir(), 'mail_test_');
    file_put_contents($path1, 'dummy');
    file_put_contents($path2, 'dummy');

    $mail = makeMessageLibreMail(['attachmentPaths' => [$path1, $path2]]);

    expect($mail->attachments())->toHaveCount(2);

    unlink($path1);
    unlink($path2);
});

it('returns empty attachments when no paths are provided', function () {
    $mail = makeMessageLibreMail(['attachmentPaths' => []]);

    expect($mail->attachments())->toBeEmpty();
});
