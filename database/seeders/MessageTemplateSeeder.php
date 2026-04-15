<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

final class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'nom' => 'Confirmation d\'inscription',
                'objet' => 'Confirmation d\'inscription à {operation}',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p>Nous avons le plaisir de vous confirmer votre inscription à <strong>{operation}</strong>.</p><p>La première séance est prévue le <strong>{date_prochaine_seance}</strong>.</p><p>N\'hésitez pas à nous contacter pour toute question.</p><p>À bientôt,<br>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
            [
                'nom' => 'Démarrage imminent',
                'objet' => '{operation} commence dans {jours_avant_prochaine_seance} jours',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p><strong>{operation}</strong> débute le <strong>{date_debut}</strong> !</p><p>La première séance « {titre_prochaine_seance} » aura lieu le <strong>{date_prochaine_seance}</strong>.</p><p>Nous avons hâte de vous accueillir.</p><p>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
            [
                'nom' => 'Rappel prochaine séance',
                'objet' => 'Rappel : séance n°{numero_prochaine_seance} dans {jours_avant_prochaine_seance} jours',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p>Nous vous rappelons votre prochaine séance de <strong>{operation}</strong> :</p><p>Séance n°{numero_prochaine_seance} — « {titre_prochaine_seance} »<br>Date : <strong>{date_prochaine_seance}</strong></p><p>À bientôt,<br>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
            [
                'nom' => 'Instructions prochaine séance',
                'objet' => 'Informations pour la séance du {date_prochaine_seance}',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p>Voici les informations pratiques pour votre prochaine séance de <strong>{operation}</strong> (séance n°{numero_prochaine_seance} — « {titre_prochaine_seance} ») :</p><p><em>[Ajoutez vos consignes ici]</em></p><p>Bonne séance !<br>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
            [
                'nom' => 'Remerciements fin de parcours',
                'objet' => 'Merci pour votre participation à {operation}',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p>Vous avez participé à {nb_seances_effectuees} séances de <strong>{operation}</strong>.</p><p>Nous tenions à vous remercier chaleureusement pour votre engagement tout au long de ce parcours.</p><p>N\'hésitez pas à nous faire part de vos retours.</p><p>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
            [
                'nom' => 'Remerciements et questionnaire',
                'objet' => 'Merci pour votre participation à {operation}',
                'corps' => '<p>{logo_operation}</p><p>Bonjour {prenom},</p><p>Merci d\'avoir participé à <strong>{operation}</strong>.</p><p>Nous serions ravis de recueillir votre avis. Pourriez-vous prendre quelques minutes pour répondre à notre questionnaire de satisfaction ?</p><p><em>[Insérez le lien vers votre questionnaire ici]</em></p><p>L\'équipe d\'animation de {type_operation}<br>{association}</p>',
            ],
        ];

        foreach ($templates as $data) {
            MessageTemplate::firstOrCreate(
                ['nom' => $data['nom']],
                array_merge(['association_id' => 1], $data)
            );
        }
    }
}
