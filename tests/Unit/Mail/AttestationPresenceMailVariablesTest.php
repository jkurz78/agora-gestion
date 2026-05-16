<?php

declare(strict_types=1);

use App\Mail\AttestationPresenceMail;

/**
 * Leak tests for AttestationPresenceMail.
 *
 * The canonical variable set for category "attestation" is defined in:
 *   - app/Enums/CategorieEmail.php → Attestation case
 *   - database/seeders/EmailTemplateSeeder.php → 'attestation' template
 *
 * Seeder template uses:
 *   objet: 'Attestation de présence — {operation}'
 *   corps: '{prenom} {type_operation} {operation} {bloc_seances}'
 */
it('AttestationPresenceMail substitue toutes les variables', function (): void {
    // Exhaustive subject covering every placeholder the seeder / CategorieEmail::Attestation exposes.
    $exhaustiveObjet = 'Attestation — {operation} — {type_operation}';

    // Exhaustive body: every {xxx} from the Attestation variable set.
    $exhaustiveCorps = implode(' ', [
        '<p>{prenom} {nom} {civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie}',
        '{operation} {type_operation} {association}',
        '{date_debut} {date_fin} {nb_seances}',
        '{numero_seance} {date_seance} {bloc_seances}</p>',
    ]);

    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Alice',
        nomParticipant: 'Durand',
        nomOperation: 'Formation Laravel',
        nomTypeOperation: 'Formation',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '10',
        numeroSeance: '3',
        dateSeance: '15/04/2026',
        customObjet: $exhaustiveObjet,
        customCorps: $exhaustiveCorps,
        pdfContent: '%PDF-dummy',
        pdfFilename: 'attestation.pdf',
        libelleArticle: null,
        blocSeances: '<p>Séance 3 — 15/04/2026</p>',
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    assertNoUnsubstitutedEmailVariables($mail);
});
