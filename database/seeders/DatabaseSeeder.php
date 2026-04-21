<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrInsert: idempotent — works whether backfill migration already created
        // the default association (migrate:fresh --seed) or not (fresh production deploy).
        DB::table('association')->updateOrInsert(
            ['id' => 1],
            [
                'nom' => 'Mon Association',
                'slug' => 'mon-association',
                'forme_juridique' => 'Association loi 1901',
                'facture_conditions_reglement' => 'Payable à réception',
                'facture_mentions_legales' => "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé",
                'facture_mentions_penalites' => "En cas de retard de paiement, pénalités au taux de 3× le taux d'intérêt légal. Indemnité forfaitaire de recouvrement : 40 € (art. D441-5 C.Com).",
                'wizard_completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // Boot tenant context so sub-seeders can query tenant-scoped models
        // (SousCategorie, TypeOperation, Tiers, EmailTemplate, etc.).
        TenantContext::boot(Association::findOrFail(1));

        $admin = User::factory()->create([
            'nom' => 'Marie Dupont',
            'email' => 'admin@monasso.fr',
            'peut_voir_donnees_sensibles' => true,
        ]);
        $admin->associations()->syncWithoutDetaching([
            1 => ['role' => 'admin', 'joined_at' => now()],
        ]);

        $jean = User::factory()->create([
            'nom' => 'Jean Martin',
            'email' => 'jean@monasso.fr',
        ]);
        $jean->associations()->syncWithoutDetaching([
            1 => ['role' => 'gestionnaire', 'joined_at' => now()],
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
            'saisie_automatisee' => true,
        ]);

        $this->call(CategoriesSeeder::class);
        $this->call(TypeOperationSeeder::class);
        $this->call(EmailTemplateSeeder::class);
        $this->call(MessageTemplateSeeder::class);
        $this->call(OperationsTiersSeeder::class);

        // Create exercice for seeded data
        $exerciceService = app(ExerciceService::class);
        $annee = $exerciceService->current();
        if (! Exercice::where('annee', $annee)->exists()) {
            $exerciceService->creerExercice($annee, User::first());
        }
    }
}
