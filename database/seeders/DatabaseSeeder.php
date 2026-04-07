<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\User;
use App\Services\ExerciceService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'nom' => 'Marie Dupont',
            'email' => 'admin@monasso.fr',
            'peut_voir_donnees_sensibles' => true,
            'role' => 'admin',
        ]);

        User::factory()->create([
            'nom' => 'Jean Martin',
            'email' => 'jean@monasso.fr',
            'role' => 'gestionnaire',
        ]);

        CompteBancaire::factory()->create([
            'nom' => 'Compte courant',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 5000.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        CompteBancaire::factory()->create([
            'nom' => 'Compte épargne',
            'iban' => 'FR7630006000019876543210456',
            'solde_initial' => 15000.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        CompteBancaire::factory()->create([
            'nom' => 'HelloAsso',
            'iban' => null,
            'solde_initial' => 0.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        \DB::table('association')->insert([
            'id' => 1,
            'nom' => 'Mon Association',
            'forme_juridique' => 'Association loi 1901',
            'facture_conditions_reglement' => 'Payable à réception',
            'facture_mentions_legales' => "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé",
            'facture_mentions_penalites' => "En cas de retard de paiement, pénalités au taux de 3× le taux d'intérêt légal. Indemnité forfaitaire de recouvrement : 40 € (art. D441-5 C.Com).",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->call(CategoriesSeeder::class);
        $this->call(TypeOperationSeeder::class);
        $this->call(EmailTemplateSeeder::class);
        $this->call(OperationsTiersSeeder::class);

        // Create exercice for seeded data
        $exerciceService = app(ExerciceService::class);
        $annee = $exerciceService->current();
        if (! Exercice::where('annee', $annee)->exists()) {
            $exerciceService->creerExercice($annee, User::first());
        }
    }
}
