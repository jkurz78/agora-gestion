<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModePaiement;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds 3 demo facture libre cases for the demo association.
 *
 * Cas 1 : devis libre accepté transformé en facture brouillon (devis_id renseigné).
 * Cas 2 : facture libre directe validée — 2 lignes MontantLibre, virement, transaction recette générée.
 * Cas 3 : facture libre directe brouillon — mix 1 Montant ref + 1 MontantLibre + 1 Texte.
 *
 * Requires TenantContext to already be booted (DatabaseSeeder boots it for asso id=1).
 * Not called in production: DatabaseSeeder gates this with app()->environment().
 * Safe to re-run : idempotent guard via check on existing data.
 */
final class FactureLibreSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ExerciceService $exerciceService */
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        // Skip if demo factures libres already exist for this exercice
        if (Facture::whereNotNull('devis_id')->exists()) {
            return;
        }

        $tiers = Tiers::orderBy('id')->first();

        if ($tiers === null) {
            return;
        }

        $sousCategorie = SousCategorie::where('nom', 'Formations')->first()
            ?? SousCategorie::orderBy('id')->first();

        if ($sousCategorie === null) {
            return;
        }

        // Pick a user from the current association as the "saisi_par" actor for demo data.
        $saisiParUser = User::orderBy('id')->first();

        if ($saisiParUser === null) {
            return;
        }

        // ── Cas 1 : Devis accepté → Facture brouillon (transformation) ───────────
        // Crée un devis accepté dédié au cas de démonstration de transformation.
        // Note : exercice/numero/accepte_le hors $fillable, assignés directement avant save().
        $devisSource = new Devis([
            'tiers_id' => $tiers->id,
            'date_emission' => Carbon::today()->subDays(5)->toDateString(),
            'date_validite' => Carbon::today()->addDays(25)->toDateString(),
            'libelle' => '[Démo] Mission d\'accompagnement stratégique',
            'statut' => StatutDevis::Accepte,
            'montant_total' => '2000.00',
        ]);
        $devisSource->exercice = $exercice;
        $devisSource->numero = 'D-'.$exercice.'-DEMO';
        $devisSource->accepte_le = Carbon::today()->subDays(2);
        $devisSource->save();

        DevisLigne::create([
            'devis_id' => $devisSource->id,
            'ordre' => 1,
            'libelle' => 'Journée d\'audit et diagnostic',
            'prix_unitaire' => '1200.00',
            'quantite' => '1.000',
            'montant' => '1200.00',
        ]);

        DevisLigne::create([
            'devis_id' => $devisSource->id,
            'ordre' => 2,
            'libelle' => 'Rapport de préconisations (forfait)',
            'prix_unitaire' => '800.00',
            'quantite' => '1.000',
            'montant' => '800.00',
        ]);

        // Facture brouillon issue du devis (recopie des lignes comme MontantLibre)
        $factureCas1 = Facture::create([
            'numero' => null,
            'date' => Carbon::today()->subDays(2)->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $tiers->id,
            'devis_id' => $devisSource->id,
            'mode_paiement_prevu' => null,
            'montant_total' => 2000.00,
            'conditions_reglement' => 'Payable à 30 jours',
            'mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
            'exercice' => $exercice,
            'saisi_par' => $saisiParUser->id,
        ]);

        FactureLigne::create([
            'facture_id' => $factureCas1->id,
            'type' => TypeLigneFacture::MontantLibre,
            'libelle' => 'Journée d\'audit et diagnostic',
            'prix_unitaire' => '1200.00',
            'quantite' => '1.000',
            'montant' => '1200.00',
            'sous_categorie_id' => $sousCategorie->id,
            'ordre' => 1,
        ]);

        FactureLigne::create([
            'facture_id' => $factureCas1->id,
            'type' => TypeLigneFacture::MontantLibre,
            'libelle' => 'Rapport de préconisations (forfait)',
            'prix_unitaire' => '800.00',
            'quantite' => '1.000',
            'montant' => '800.00',
            'sous_categorie_id' => $sousCategorie->id,
            'ordre' => 2,
        ]);

        // ── Cas 2 : Facture libre directe validée — transaction recette générée ──
        $factureCas2 = Facture::create([
            'numero' => sprintf('F-%d-9901', $exercice),
            'date' => Carbon::today()->subDays(1)->toDateString(),
            'statut' => StatutFacture::Validee,
            'tiers_id' => $tiers->id,
            'devis_id' => null,
            'mode_paiement_prevu' => ModePaiement::Virement,
            'montant_total' => 1400.00,
            'conditions_reglement' => 'Payable à réception',
            'mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
            'exercice' => $exercice,
            'saisi_par' => $saisiParUser->id,
        ]);

        // Transaction recette "à recevoir" générée à la validation
        $transaction = Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'tiers_id' => $tiers->id,
            'date' => Carbon::today()->subDays(1)->toDateString(),
            'libelle' => "Facture {$factureCas2->numero}",
            'montant_total' => 1400.00,
            'mode_paiement' => ModePaiement::Virement->value,
            'statut_reglement' => StatutReglement::EnAttente->value,
        ]);

        $ligneTx1 = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 1200.00,
            'notes' => 'Formation initiale (2 jours)',
        ]);

        $ligneTx2 = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 200.00,
            'notes' => 'Supports pédagogiques',
        ]);

        FactureLigne::create([
            'facture_id' => $factureCas2->id,
            'type' => TypeLigneFacture::MontantLibre,
            'libelle' => 'Formation initiale (2 jours)',
            'prix_unitaire' => '600.00',
            'quantite' => '2.000',
            'montant' => '1200.00',
            'sous_categorie_id' => $sousCategorie->id,
            'transaction_ligne_id' => $ligneTx1->id,
            'ordre' => 1,
        ]);

        FactureLigne::create([
            'facture_id' => $factureCas2->id,
            'type' => TypeLigneFacture::MontantLibre,
            'libelle' => 'Supports pédagogiques',
            'prix_unitaire' => '200.00',
            'quantite' => '1.000',
            'montant' => '200.00',
            'sous_categorie_id' => $sousCategorie->id,
            'transaction_ligne_id' => $ligneTx2->id,
            'ordre' => 2,
        ]);

        // Pivot facture_transaction
        DB::table('facture_transaction')->insert([
            'facture_id' => $factureCas2->id,
            'transaction_id' => $transaction->id,
        ]);

        // ── Cas 3 : Facture libre directe brouillon — mix Montant ref + MontantLibre + Texte ──
        // Transaction pré-existante pour la ligne Montant ref
        $txRefCas3 = Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'tiers_id' => $tiers->id,
            'date' => Carbon::today()->subDays(3)->toDateString(),
            'libelle' => '[Démo] Recette à refacturer (mix)',
            'montant_total' => 300.00,
            'mode_paiement' => ModePaiement::Virement->value,
            'statut_reglement' => StatutReglement::EnAttente->value,
        ]);

        $ligneTxRef = TransactionLigne::create([
            'transaction_id' => $txRefCas3->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 300.00,
            'notes' => 'Recette à refacturer — ligne démo',
        ]);

        $factureCas3 = Facture::create([
            'numero' => null,
            'date' => Carbon::today()->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $tiers->id,
            'devis_id' => null,
            'mode_paiement_prevu' => ModePaiement::Virement,
            'montant_total' => 800.00,
            'conditions_reglement' => 'Payable à réception',
            'mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
            'exercice' => $exercice,
            'saisi_par' => $saisiParUser->id,
        ]);

        // Ligne 1 : Montant ref — pointe une transaction_ligne existante
        FactureLigne::create([
            'facture_id' => $factureCas3->id,
            'type' => TypeLigneFacture::Montant,
            'libelle' => 'Recette à refacturer — ligne démo',
            'montant' => '300.00',
            'transaction_ligne_id' => $ligneTxRef->id,
            'ordre' => 1,
        ]);

        // Ligne 2 : MontantLibre — prestation libre
        FactureLigne::create([
            'facture_id' => $factureCas3->id,
            'type' => TypeLigneFacture::MontantLibre,
            'libelle' => 'Prestation de conseil (1 journée)',
            'prix_unitaire' => '500.00',
            'quantite' => '1.000',
            'montant' => '500.00',
            'sous_categorie_id' => $sousCategorie->id,
            'ordre' => 2,
        ]);

        // Ligne 3 : Texte — information contractuelle
        FactureLigne::create([
            'facture_id' => $factureCas3->id,
            'type' => TypeLigneFacture::Texte,
            'libelle' => 'Prestation réalisée selon devis accepté et annexe contractuelle jointe.',
            'ordre' => 3,
        ]);

        // Rattache la transaction de référence au pivot
        DB::table('facture_transaction')->insert([
            'facture_id' => $factureCas3->id,
            'transaction_id' => $txRefCas3->id,
        ]);
    }
}
