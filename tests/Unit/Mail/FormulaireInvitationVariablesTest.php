<?php

declare(strict_types=1);

use App\Mail\FormulaireInvitation;

/**
 * Leak tests for FormulaireInvitation.
 *
 * The canonical variable set for category "formulaire" is defined in:
 *   - app/Enums/CategorieEmail.php → Formulaire case
 *   - database/seeders/EmailTemplateSeeder.php → 'formulaire' template
 *
 * Seeder objet: 'Action requise : Formulaire à compléter pour votre inscription au parcours {operation}'
 * Seeder corps uses: {prenom}, {type_operation}, {nb_seances}, {date_debut}, {date_fin}
 *
 * Full Formulaire variable set also includes:
 *   {bloc_liens}, {url}, {code}, {date_expiration}, {nom},
 *   {civilite}, {politesse}, {civilite_nom}, {politesse_nom},
 *   {civilite_prenom_nom}, {politesse_prenom_nom}, {salutation}, {adresse_polie}
 *   {logo}, {logo_operation}
 */
it('FormulaireInvitation substitue toutes les variables', function (): void {
    // The seeder's objet — exercises {operation} which is the key risk variable.
    $exhaustiveObjet = 'Formulaire pour {operation} — {type_operation}';

    // Exhaustive corps: every {xxx} from CategorieEmail::Formulaire.
    // Note: when {bloc_liens} or {url} is present, the auto-block is suppressed (by design).
    $exhaustiveCorps = implode(' ', [
        '<p>{prenom} {nom} {operation} {type_operation}',
        '{date_debut} {date_fin} {nb_seances}',
        '{civilite} {politesse}',
        '{civilite_nom} {politesse_nom}',
        '{civilite_prenom_nom} {politesse_prenom_nom}',
        '{salutation} {adresse_polie}',
        '{url} {code} {date_expiration}</p>',
        // {bloc_liens} is a rendered HTML block — its presence disables the auto block.
        // Test it separately to avoid suppressing {url} detection.
    ]);

    $mail = new FormulaireInvitation(
        prenomParticipant: 'Alice',
        nomParticipant: 'Durand',
        nomOperation: 'Formation Laravel',
        nomTypeOperation: 'Formation',
        formulaireUrl: 'https://example.com/formulaire/tok123',
        tokenCode: 'TOK-123',
        dateExpiration: '31/12/2025',
        dateDebut: '01/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '10',
        customObjet: $exhaustiveObjet,
        customCorps: $exhaustiveCorps,
        libelleArticle: null,
        typeOperationId: null,
        civilite: 'M.',
        politesse: 'Monsieur',
    );

    assertNoUnsubstitutedEmailVariables($mail);
});

it('FormulaireInvitation substitue {bloc_liens} dans le corps', function (): void {
    $corps = '<p>Bonjour {prenom},</p><p>{bloc_liens}</p>';

    $mail = new FormulaireInvitation(
        prenomParticipant: 'Alice',
        nomParticipant: 'Durand',
        nomOperation: 'Formation Laravel',
        nomTypeOperation: 'Formation',
        formulaireUrl: 'https://example.com/formulaire/tok123',
        tokenCode: 'TOK-123',
        dateExpiration: '31/12/2025',
        customObjet: 'Formulaire pour {operation}',
        customCorps: $corps,
    );

    assertNoUnsubstitutedEmailVariables($mail);
});
