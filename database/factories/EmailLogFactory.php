<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategorieEmail;
use App\Models\EmailLog;
use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
final class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    public function definition(): array
    {
        return [
            'tiers_id' => Tiers::factory(),
            'participant_id' => null,
            'operation_id' => null,
            'categorie' => CategorieEmail::Message->value,
            'email_template_id' => null,
            'destinataire_email' => $this->faker->safeEmail(),
            'destinataire_nom' => $this->faker->name(),
            'objet' => $this->faker->sentence(),
            'objet_rendu' => null,
            'corps_html' => '<p>'.$this->faker->paragraph().'</p>',
            'statut' => 'envoye',
            'erreur_message' => null,
            'envoye_par' => null,
            'campagne_id' => null,
            'tracking_token' => null,
            'attachment_path' => null,
        ];
    }

    public function avecErreur(string $message = 'SMTP error'): self
    {
        return $this->state(fn () => [
            'statut' => 'erreur',
            'erreur_message' => $message,
        ]);
    }

    public function avecPieceJointe(string $path = 'tmp/test.pdf'): self
    {
        return $this->state(fn () => [
            'attachment_path' => $path,
        ]);
    }
}
