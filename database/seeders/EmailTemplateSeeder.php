<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::updateOrCreate(
            ['categorie' => 'formulaire', 'type_operation_id' => null],
            [
                'objet' => 'Action requise : Formulaire à compléter pour votre inscription au parcours {operation}',
                'corps' => '<p>Bonjour {prenom},</p>'
                    .'<p>Afin de compléter votre dossier d\'inscription au parcours {type_operation}, nous vous remercions de compléter le formulaire dont le lien est ci-dessous.</p>'
                    .'<p>Rappel : ce parcours se déroulera sur {nb_seances} séances du {date_debut} au {date_fin}.</p>'
                    .'<p>Cordialement,<br>L\'équipe encadrante {type_operation}</p>',
            ],
        );

        EmailTemplate::updateOrCreate(
            ['categorie' => 'attestation', 'type_operation_id' => null],
            [
                'objet' => 'Attestation de présence — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom}</strong>,</p>'
                    .'<p>Veuillez trouver ci-joint votre attestation de présence pour <strong>{operation}</strong>.</p>'
                    .'<p>Séance n°{numero_seance} du {date_seance}.</p>'
                    .'<p>Cordialement,<br>L\'équipe</p>',
            ],
        );

        EmailTemplate::updateOrCreate(
            ['categorie' => 'facture', 'type_operation_id' => null],
            [
                'objet' => 'Facture n°{numero_facture} — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom} {nom}</strong>,</p>'
                    .'<p>Veuillez trouver ci-joint la facture n°<strong>{numero_facture}</strong> du {date_facture} '
                    .'relative à <strong>{operation}</strong>.</p>'
                    .'<p>Cordialement,<br>L\'équipe</p>',
            ],
        );
    }
}
