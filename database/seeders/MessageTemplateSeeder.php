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
                'nom' => 'Rappel séance J-2',
                'objet' => 'Rappel : prochaine séance le {date_prochaine_seance}',
                'corps' => '<p>Bonjour {prenom},</p><p>Nous vous rappelons que la prochaine séance de {operation} (séance n°{numero_prochaine_seance}) aura lieu le <strong>{date_prochaine_seance}</strong>.</p><p>À bientôt !</p>',
            ],
            [
                'nom' => 'Remerciements et questionnaire',
                'objet' => 'Merci pour votre participation à {operation}',
                'corps' => '<p>Bonjour {prenom},</p><p>Merci d\'avoir participé à <strong>{operation}</strong>.</p><p>Nous serions ravis de recueillir votre avis. Pourriez-vous prendre quelques minutes pour répondre à notre questionnaire de satisfaction ?</p><p>Cordialement,<br>L\'équipe {type_operation}</p>',
            ],
            [
                'nom' => 'Information logistique',
                'objet' => 'Information : {operation}',
                'corps' => '<p>Bonjour {prenom},</p><p>Nous souhaitons vous informer d\'un changement concernant <strong>{operation}</strong>.</p><p>Cordialement,<br>L\'équipe {type_operation}</p>',
            ],
            [
                'nom' => 'Bienvenue',
                'objet' => 'Bienvenue dans {operation} !',
                'corps' => '<p>Bonjour {prenom},</p><p>Nous avons le plaisir de vous confirmer votre inscription à <strong>{operation}</strong>.</p><p>La première séance est prévue le {date_prochaine_seance}.</p><p>N\'hésitez pas à nous contacter pour toute question.</p><p>À bientôt !</p>',
            ],
        ];

        foreach ($templates as $data) {
            MessageTemplate::firstOrCreate(
                ['nom' => $data['nom']],
                $data
            );
        }
    }
}
