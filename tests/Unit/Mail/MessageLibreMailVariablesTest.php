<?php

declare(strict_types=1);

use App\Mail\MessageLibreMail;

/**
 * Leak tests for MessageLibreMail.
 *
 * The canonical variable set for category "message" is defined in:
 *   - app/Enums/CategorieEmail.php → Message case
 *   - database/seeders/MessageTemplateSeeder.php (6 templates)
 *
 * The seeders use (exhaustive union):
 *   {logo_operation}, {prenom}, {operation}, {date_prochaine_seance},
 *   {date_debut}, {type_operation}, {association}, {jours_avant_prochaine_seance},
 *   {titre_prochaine_seance}, {numero_prochaine_seance}, {nb_seances_effectuees}
 *
 * Plus the full Message set from CategorieEmail::Message.
 */
it('MessageLibreMail substitue toutes les variables', function (): void {
    // Exhaustive subject covering all seeder + enum placeholders.
    $exhaustiveObjet = implode(' ', [
        '{operation} {type_operation} {association}',
        '{jours_avant_prochaine_seance} {numero_prochaine_seance}',
        '{date_prochaine_seance} {nb_seances_effectuees}',
    ]);

    // Exhaustive body: every {xxx} from CategorieEmail::Message.
    $exhaustiveCorps = implode(' ', [
        '<p>{prenom} {nom} {email_participant} {association}',
        '{civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie}',
        '{operation} {type_operation}',
        '{date_debut} {date_fin} {nb_seances}',
        '{date_prochaine_seance} {numero_prochaine_seance} {titre_prochaine_seance}',
        '{jours_avant_prochaine_seance}',
        '{date_precedente_seance} {numero_precedente_seance} {titre_precedente_seance}',
        '{nb_seances_effectuees} {nb_seances_restantes}',
        '{table_seances} {table_seances_a_venir}</p>',
        // logo variables — resolved via EmailLogo::variables(), empty string when no logo in test env
        '<p>{logo} {logo_operation}</p>',
    ]);

    $mail = new MessageLibreMail(
        prenomParticipant: 'Alice',
        nomParticipant: 'Durand',
        emailParticipant: 'alice@test.fr',
        operationNom: 'Formation Laravel',
        typeOperationNom: 'Formation',
        libelleArticle: null,
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nbSeances: 10,
        dateProchainSeance: '15/04/2026',
        datePrecedenteSeance: '01/04/2026',
        numeroProchainSeance: 5,
        numeroPrecedenteSeance: 4,
        titreProchainSeance: 'Atelier peinture',
        titrePrecedenteSeance: 'Atelier dessin',
        joursAvantProchaineSeance: 3,
        nbSeancesEffectuees: 4,
        nbSeancesRestantes: 6,
        objet: $exhaustiveObjet,
        corps: $exhaustiveCorps,
        attachmentPaths: [],
        typeOperationId: null,
        trackingToken: null,
        seances: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    assertNoUnsubstitutedEmailVariables($mail);
});
