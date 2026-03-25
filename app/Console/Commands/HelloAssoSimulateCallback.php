<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HelloAssoParametres;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class HelloAssoSimulateCallback extends Command
{
    protected $signature = 'helloasso:simulate-callback
                            {--type=Order : Type d\'événement (Order, Payment, Form)}
                            {--form-type=Membership : Type de formulaire (Membership, Donation, Event)}
                            {--name=Jean Dupont : Nom du payeur}';

    protected $description = 'Simule un appel callback HelloAsso pour tester la chaîne complète';

    public function handle(): int
    {
        $parametres = HelloAssoParametres::where('association_id', 1)->first();

        if ($parametres === null || $parametres->callback_token === null) {
            $this->error('Aucun token callback configuré. Sauvegardez d\'abord les paramètres HelloAsso.');

            return self::FAILURE;
        }

        $nameParts = explode(' ', $this->option('name'), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? 'Test';

        $payload = [
            'eventType' => $this->option('type'),
            'data' => [
                'formType' => $this->option('form-type'),
                'formSlug' => 'formulaire-test',
                'formName' => 'Formulaire de test',
                'payer' => [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                ],
            ],
        ];

        $url = url("/api/helloasso/callback/{$parametres->callback_token}");
        $this->info("Envoi vers : {$url}");
        $this->info('Payload : '.json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = Http::post($url, $payload);

        if ($response->successful()) {
            $this->info('Callback simulé avec succès (HTTP '.$response->status().')');

            return self::SUCCESS;
        }

        $this->error('Erreur HTTP '.$response->status().' : '.$response->body());

        return self::FAILURE;
    }
}
