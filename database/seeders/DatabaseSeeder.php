<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 2 Users
        $admin = User::factory()->create([
            'nom' => 'Marie Dupont',
            'email' => 'admin@svs.fr',
        ]);

        $user = User::factory()->create([
            'nom' => 'Jean Martin',
            'email' => 'jean@svs.fr',
        ]);

        // 3 Comptes bancaires
        $compteCourant = CompteBancaire::factory()->create([
            'nom' => 'Compte courant',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 5000.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        $livret = CompteBancaire::factory()->create([
            'nom' => 'Livret A',
            'iban' => 'FR7630006000019876543210456',
            'solde_initial' => 15000.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        $caisse = CompteBancaire::factory()->create([
            'nom' => 'Caisse espèces',
            'iban' => null,
            'solde_initial' => 200.00,
            'date_solde_initial' => '2025-09-01',
        ]);

        // Categories & Sous-categories - Dépenses
        $catFonctionnement = Categorie::factory()->create([
            'nom' => 'Fonctionnement',
            'type' => TypeCategorie::Depense,
        ]);

        SousCategorie::factory()->create(['categorie_id' => $catFonctionnement->id, 'nom' => 'Loyer']);
        SousCategorie::factory()->create(['categorie_id' => $catFonctionnement->id, 'nom' => 'Assurance']);
        SousCategorie::factory()->create(['categorie_id' => $catFonctionnement->id, 'nom' => 'Fournitures']);
        SousCategorie::factory()->create(['categorie_id' => $catFonctionnement->id, 'nom' => 'Téléphone & Internet']);

        $catActivitesDep = Categorie::factory()->create([
            'nom' => 'Activités',
            'type' => TypeCategorie::Depense,
        ]);

        SousCategorie::factory()->create(['categorie_id' => $catActivitesDep->id, 'nom' => 'Matériel pédagogique']);
        SousCategorie::factory()->create(['categorie_id' => $catActivitesDep->id, 'nom' => 'Intervenants']);
        SousCategorie::factory()->create(['categorie_id' => $catActivitesDep->id, 'nom' => 'Transport']);

        // Categories & Sous-categories - Recettes
        $catCotisations = Categorie::factory()->create([
            'nom' => 'Cotisations',
            'type' => TypeCategorie::Recette,
        ]);

        SousCategorie::factory()->create(['categorie_id' => $catCotisations->id, 'nom' => 'Cotisations membres']);

        $catSubventions = Categorie::factory()->create([
            'nom' => 'Subventions',
            'type' => TypeCategorie::Recette,
        ]);

        SousCategorie::factory()->create(['categorie_id' => $catSubventions->id, 'nom' => 'Subvention mairie']);
        SousCategorie::factory()->create(['categorie_id' => $catSubventions->id, 'nom' => 'Subvention département']);
        SousCategorie::factory()->create(['categorie_id' => $catSubventions->id, 'nom' => 'Subvention état']);

        $catActivitesRec = Categorie::factory()->create([
            'nom' => 'Activités',
            'type' => TypeCategorie::Recette,
        ]);

        SousCategorie::factory()->create(['categorie_id' => $catActivitesRec->id, 'nom' => 'Inscriptions']);
        SousCategorie::factory()->create(['categorie_id' => $catActivitesRec->id, 'nom' => 'Ventes']);

        // 2 Opérations
        Operation::factory()->withSeances(10)->create([
            'nom' => 'Cours de français',
            'description' => 'Cours hebdomadaires de français pour adultes',
            'date_debut' => '2025-09-15',
            'date_fin' => '2026-06-30',
        ]);

        Operation::factory()->withSeances(5)->create([
            'nom' => 'Sortie culturelle',
            'description' => 'Sorties et visites culturelles trimestrielles',
            'date_debut' => '2025-10-01',
            'date_fin' => '2026-05-31',
        ]);

        // Some sample depenses
        Depense::factory()->count(5)->create([
            'saisi_par' => $admin->id,
            'compte_id' => $compteCourant->id,
        ]);

        // Some sample recettes
        Recette::factory()->count(3)->create([
            'saisi_par' => $admin->id,
            'compte_id' => $compteCourant->id,
        ]);

        // Some membres with cotisations
        $membresAvecCotisation = Tiers::factory()->membre()->count(5)->create();
        foreach ($membresAvecCotisation as $membre) {
            Cotisation::factory()->create([
                'tiers_id' => $membre->id,
                'exercice' => 2025,
            ]);
        }
        Tiers::factory()->membre()->count(3)->create();

        // Some tiers (donateurs) and dons
        $tiers = Tiers::factory()->pourRecettes()->create();
        Don::factory()->count(2)->create([
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
            'compte_id' => $compteCourant->id,
        ]);
    }
}
