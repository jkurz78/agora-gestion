<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds ≥ 3 demo devis libres with varied statuts for the demo association.
 *
 * Requires TenantContext to already be booted (DatabaseSeeder boots it for asso id=1).
 * Not called in production: DatabaseSeeder gates this with app()->environment().
 */
class DevisLibreSeeder extends Seeder
{
    public function run(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $admin = User::where('email', 'admin@monasso.fr')->first();

        // Retrieve or pick a few tiers from the demo association
        $tiersList = Tiers::orderBy('id')->take(4)->get();

        if ($tiersList->isEmpty()) {
            // Fallback: nothing to seed if no tiers exist yet
            return;
        }

        $tiersA = $tiersList->get(0);
        $tiersB = $tiersList->get(1) ?? $tiersA;
        $tiersC = $tiersList->get(2) ?? $tiersA;

        // ── Devis 1 : brouillon avec 2 lignes ───────────────────────────────────
        $devis1 = Devis::create([
            'tiers_id' => $tiersA->id,
            'date_emission' => Carbon::today()->subDays(10)->toDateString(),
            'date_validite' => Carbon::today()->addDays(20)->toDateString(),
            'libelle' => 'Mission conseil stratégique',
            'statut' => StatutDevis::Brouillon,
            'montant_total' => '0.00',
            'saisi_par_user_id' => $admin?->id,
        ]);
        $devis1->exercice = $exercice;
        $devis1->save();

        DevisLigne::create([
            'devis_id' => $devis1->id,
            'ordre' => 1,
            'libelle' => 'Audit initial',
            'prix_unitaire' => '800.00',
            'quantite' => '1.000',
            'montant' => '800.00',
        ]);

        DevisLigne::create([
            'devis_id' => $devis1->id,
            'ordre' => 2,
            'libelle' => 'Rapport de préconisations',
            'prix_unitaire' => '350.00',
            'quantite' => '2.000',
            'montant' => '700.00',
        ]);

        $devis1->update(['montant_total' => '1500.00']);

        // ── Devis 2 : envoyé (numéroté) ─────────────────────────────────────────
        $devis2 = Devis::create([
            'tiers_id' => $tiersB->id,
            'date_emission' => Carbon::today()->subDays(30)->toDateString(),
            'date_validite' => Carbon::today()->addDays(1)->toDateString(),
            'libelle' => 'Formation sécurité incendie',
            'statut' => StatutDevis::Envoye,
            'montant_total' => '1200.00',
            'saisi_par_user_id' => $admin?->id,
        ]);
        $devis2->numero = 'D-'.$exercice.'-001';
        $devis2->exercice = $exercice;
        $devis2->save();

        DevisLigne::create([
            'devis_id' => $devis2->id,
            'ordre' => 1,
            'libelle' => 'Session de formation (4h)',
            'prix_unitaire' => '400.00',
            'quantite' => '3.000',
            'montant' => '1200.00',
        ]);

        // ── Devis 3 : accepté avec traces ───────────────────────────────────────
        $devis3 = Devis::create([
            'tiers_id' => $tiersC->id,
            'date_emission' => Carbon::today()->subDays(45)->toDateString(),
            'date_validite' => Carbon::today()->subDays(15)->toDateString(),
            'libelle' => 'Prestation communication',
            'statut' => StatutDevis::Accepte,
            'montant_total' => '2400.00',
            'saisi_par_user_id' => $admin?->id,
        ]);
        $devis3->numero = 'D-'.$exercice.'-002';
        $devis3->exercice = $exercice;
        $devis3->accepte_par_user_id = $admin?->id;
        $devis3->accepte_le = Carbon::today()->subDays(20);
        $devis3->save();

        DevisLigne::create([
            'devis_id' => $devis3->id,
            'ordre' => 1,
            'libelle' => 'Création charte graphique',
            'prix_unitaire' => '1200.00',
            'quantite' => '1.000',
            'montant' => '1200.00',
        ]);

        DevisLigne::create([
            'devis_id' => $devis3->id,
            'ordre' => 2,
            'libelle' => 'Déclinaison supports (flyers, affiches)',
            'prix_unitaire' => '400.00',
            'quantite' => '3.000',
            'montant' => '1200.00',
        ]);

        // ── Devis 4 : refusé ─────────────────────────────────────────────────────
        $devis4 = Devis::create([
            'tiers_id' => $tiersA->id,
            'date_emission' => Carbon::today()->subDays(60)->toDateString(),
            'date_validite' => Carbon::today()->subDays(30)->toDateString(),
            'libelle' => 'Maintenance informatique annuelle',
            'statut' => StatutDevis::Refuse,
            'montant_total' => '3600.00',
            'saisi_par_user_id' => $admin?->id,
        ]);
        $devis4->numero = 'D-'.$exercice.'-003';
        $devis4->exercice = $exercice;
        $devis4->refuse_par_user_id = $admin?->id;
        $devis4->refuse_le = Carbon::today()->subDays(35);
        $devis4->save();

        DevisLigne::create([
            'devis_id' => $devis4->id,
            'ordre' => 1,
            'libelle' => 'Contrat de maintenance (12 mois)',
            'prix_unitaire' => '300.00',
            'quantite' => '12.000',
            'montant' => '3600.00',
        ]);
    }
}
