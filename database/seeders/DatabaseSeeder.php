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
            'email' => 'admin@svs.fr',
        ]);

        User::factory()->create([
            'nom' => 'Jean Martin',
            'email' => 'jean@svs.fr',
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
            'nom' => 'Soigner Vivre Sourire (SVS)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->call(CategoriesSeeder::class);
        $this->call(TypeOperationSeeder::class);
        $this->call(OperationsTiersSeeder::class);

        // Create exercice for seeded data
        $exerciceService = app(ExerciceService::class);
        $annee = $exerciceService->current();
        if (! Exercice::where('annee', $annee)->exists()) {
            $exerciceService->creerExercice($annee, User::first());
        }
    }
}
