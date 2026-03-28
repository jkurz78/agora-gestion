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
                'objet' => 'Formulaire à compléter — {operation}',
                'corps' => '<p>Bonjour <strong>{prenom}</strong>,</p>'
                    .'<p>Nous vous invitons à compléter votre formulaire d\'inscription pour <strong>{operation}</strong> ({type_operation}).</p>'
                    .'<p>Dates : du {date_debut} au {date_fin}.</p>'
                    .'<p>Merci de compléter ce formulaire dans les meilleurs délais.</p>'
                    .'<p>Cordialement,<br>L\'équipe</p>',
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
